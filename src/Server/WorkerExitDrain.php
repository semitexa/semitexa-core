<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Lifecycle\WorkerDrainSignal;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\Row;

/**
 * One pass of a leaving worker's drain. {@see SwooleEvent::WorkerExit} fires
 * again and again for the whole reload_async window; each firing calls pass().
 *
 * Framework guarantee: a worker asked to exit must be ABLE to exit. Two things
 * keep workers hostage past max_wait_time: recurring timers that keep firing
 * into the teardown, and coroutines parked on sockets that died with the
 * restart (a DB ping, an LLM HTTP call) — the latter end as Swoole's FATAL
 * "all coroutines are asleep - deadlock!". So every user timer is cleared and
 * every parked coroutine cancelled: cancellation turns an immortal wait into a
 * catchable failure on code that already handles transport errors. A request
 * being answered is the exception, see {@see WorkerExitDrainPolicy}. A third
 * hostage is a connection with nothing left to run, see closeLeftoverConnections().
 *
 * Built before start(), like {@see WorkerExitDrainReporter}, so every worker
 * forks its own copy: the state here is one worker's last few seconds.
 */
final class WorkerExitDrain
{
    private ?float $beganAt = null;
    private ?int $wakeAtGraceEnd = null;
    /** @var array<int, true> */
    private array $everCancelled = [];
    private bool $toldWaiting = false;
    /** @var array<int, true> */
    private array $closedSessions = [];
    private bool $toldClosed = false;

    public function __construct(
        private readonly WorkerExitDrainPolicy $policy,
        private readonly WorkerExitDrainReporter $reporter,
        private readonly ServedConnections $connections,
    ) {
    }

    public function pass(int $workerId, \Swoole\Server $server): void
    {
        // FIRST, before any cancellation is attempted: work that cancel() cannot reach
        // stands down by reading this instead. cancel() is an attempt, not a signal —
        // when it is refused it leaves nothing behind, so a coroutine parked on file
        // I/O would otherwise read on to completion and hold the worker to its exit
        // timeout. See WorkerDrainSignal for the measurement.
        WorkerDrainSignal::begin();
        $this->beganAt ??= self::monotonicSeconds();
        $sinceBegan = self::monotonicSeconds() - $this->beganAt;

        // Every timer goes but one: the wake-up at the end of the grace window.
        // Passes come from reactor iterations, and a worker left with nothing but
        // a spared request on a quiet socket might not iterate again before the
        // forced kill — this one makes sure the drain gets its pass when the
        // window closes.
        foreach (self::timerIds() as $timerId) {
            if ($timerId !== $this->wakeAtGraceEnd) {
                \Swoole\Timer::clear($timerId);
            }
        }

        $current = Row::asInt(\Swoole\Coroutine::getCid());
        $cancelled = 0;
        $spared = 0;
        $stubborn = [];
        // The stub types this `mixed`; an older runtime can return false.
        $live = \Swoole\Coroutine::listCoroutines();
        foreach (is_iterable($live) ? $live : [] as $cid) {
            $cid = Row::asInt($cid);
            if ($cid === $current) {
                continue;
            }
            // A request a moment from its response is let finish; it is
            // cancelled only if it is still running when the window closes.
            if (!$this->policy->shouldCancel(InFlightRequests::servesRequest($cid), $sinceBegan)) {
                $spared++;
                continue;
            }
            if (\Swoole\Coroutine::cancel($cid)) {
                // Counted once: past the window a request that parks again
                // while writing its error is cancelled again on every pass,
                // and passes can run thousands of times a second.
                if (!isset($this->everCancelled[$cid])) {
                    $this->everCancelled[$cid] = true;
                    $cancelled++;
                }
                continue;
            }
            // Cancellation REFUSED. This is the interesting case and it used
            // to be invisible: the old counter only tallied successes, so a
            // worker held past max_wait_time reported "cancelled N" and said
            // nothing about the one coroutine actually keeping it hostage.
            // A coroutine blocked inside a driver's syscall (a PDO query, for
            // instance) cannot be interrupted, and that is what turns into
            // "worker exit timeout, forced termination" — and, on a bad day,
            // into a crash during forced teardown.
            $stubborn[$cid] = CoroutineSite::describe($cid);
        }

        // The wake-up exists exactly while something is spared. Nothing left to
        // spare: it must not outlive its reason — a pending timer keeps the
        // event loop, and so the worker, alive, and connections Swoole hands a
        // leaving worker in that time get an empty reply when it finally exits
        // (measured: 13 of 900 requests cut at 2.02 s). Something spared again
        // — a keep-alive request that arrived after the last one finished —
        // needs it back, on the same clock: without it no pass came after the
        // window and the manager killed the worker (measured: 64 forced
        // terminations in one E2E run at SWOOLE_MAX_REQUEST=20).
        if ($spared === 0 && $this->wakeAtGraceEnd !== null) {
            \Swoole\Timer::clear($this->wakeAtGraceEnd);
            $this->wakeAtGraceEnd = null;
        } elseif ($spared > 0 && $this->wakeAtGraceEnd === null) {
            $remainingMs = (int) ceil(($this->policy->graceSeconds - $sinceBegan) * 1000) + 10;
            $wake = \Swoole\Timer::after(max(10, $remainingMs), static function (): void {
            });
            $this->wakeAtGraceEnd = $wake;
        }
        // Counted after the cancellations: cancel() resumes a coroutine at once, and
        // one that ended there must not put the close off to the pass at the deadline.
        if (self::othersAlive($current) === 0) {
            $this->closeLeftoverConnections($server, $workerId);
        }
        if ($spared > 0 && !$this->toldWaiting) {
            // The one thing keeping the worker now is requests being answered;
            // said once, so a slow exit is not a mystery.
            $this->toldWaiting = true;
            StaticLoggerBridge::warning('lifecycle', 'Worker exit waiting for requests in flight', [
                'worker_id' => $workerId,
                'requests' => $spared,
                'grace_seconds' => $this->policy->graceSeconds,
            ]);
        }
        if ($cancelled > 0) {
            // Operational breadcrumb: which exits actually had parked work.
            // warning(), not debug(): debug() returns early when no logger is
            // wired, while warning()/error() fall back to FallbackErrorLogger.
            // Teardown is exactly when the container may not resolve a logger,
            // so debug() would drop this line in the one situation it exists
            // for. Not noise either — a non-zero count means work was parked.
            StaticLoggerBridge::warning('lifecycle', 'Worker exit cancelled parked coroutines', [
                'worker_id' => $workerId,
                'cancelled' => $cancelled,
            ]);
        }
        // WorkerExit re-fires for the whole drain window, so this loop used to
        // re-state an unchanging fact on every pass: measured, 97 stuck coroutines
        // wrote 110061 lines and buried every real error in the same file. The
        // reporter keeps the first sighting and re-alarms on a clock, so a worker
        // held hostage still says so — with how long it has been held, which is the
        // fact that explains a restart that appears to hang.
        foreach ($this->reporter->report($stubborn, self::monotonicSeconds()) as $sighting) {
            // Warning, not info: this is the coroutine that will hold the
            // worker to its exit timeout, and the frame trail is the only
            // thing that says which one.
            StaticLoggerBridge::warning('lifecycle', 'Worker exit: coroutine refused cancellation', [
                'worker_id' => $workerId,
                'cid' => $sighting['cid'],
                'parked_at' => $sighting['where'],
                'stuck_for_seconds' => $sighting['stuck_for'],
                'repeat' => $sighting['repeat'],
            ]);
        }
    }

    /**
     * Nothing runs any more, yet Swoole can still wait on a connection. The stop
     * removes every connection's read event, but a response still flushing then
     * (a big page, a slow reader) keeps its write event, and when that flush ends
     * Swoole sets the socket back to READ — re-arming the read the stop removed.
     * An idle keep-alive client on it then holds the worker to "worker exit
     * timeout, forced termination" with no coroutine and no timer left (measured:
     * 23 in one platform-ui E2E run at SWOOLE_MAX_REQUEST=20, each worker's epoll
     * holding exactly such a client socket). Closing is what the forced exit does
     * to them anyway, only seconds later; a close on a flushing connection is
     * queued behind its data, so no response is cut. A request still arriving,
     * with no coroutine yet, is cut, as the forced exit would cut it. The sessions come from
     * {@see ServedConnections}: $server->connections can miss exactly these.
     */
    private function closeLeftoverConnections(\Swoole\Server $server, int $workerId): void
    {
        // Only BASE mode keeps a worker's connections in the worker; elsewhere a
        // close is forwarded and would cut a client another process is serving.
        if ($server->mode !== SWOOLE_BASE) {
            return;
        }
        $closed = 0;
        foreach ($this->connections->sessionIds() as $sessionId) {
            if (isset($this->closedSessions[$sessionId])) {
                continue;
            }
            $info = $server->getClientInfo($sessionId);
            if (!is_array($info)) {
                $this->closedSessions[$sessionId] = true;
                continue;
            }
            if (($info['reactor_id'] ?? null) !== $workerId) {
                continue;
            }
            // Marked only once closed: a session Swoole is still releasing refuses
            // the close, and a later pass must try it again.
            if ($server->close($sessionId)) {
                $this->closedSessions[$sessionId] = true;
                $closed++;
            }
        }
        // Once per exit: passes repeat for the whole window.
        if ($closed > 0 && !$this->toldClosed) {
            $this->toldClosed = true;
            StaticLoggerBridge::warning('lifecycle', 'Worker exit closed connections nothing was running for', [
                'worker_id' => $workerId,
                'connections' => $closed,
            ]);
        }
    }

    private static function othersAlive(int $current): int
    {
        $alive = 0;
        $live = \Swoole\Coroutine::listCoroutines();
        foreach (is_iterable($live) ? $live : [] as $cid) {
            if (Row::asInt($cid) !== $current) {
                $alive++;
            }
        }

        return $alive;
    }

    /** MONOTONIC seconds, the clock the reporter requires. */
    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /** @return list<int> */
    private static function timerIds(): array
    {
        $ids = [];
        foreach (\Swoole\Timer::list() as $id) {
            if (is_int($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
