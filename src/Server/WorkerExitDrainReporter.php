<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * Decides how often a worker that cannot finish exiting is allowed to say so.
 *
 * {@see SwooleEvent::WorkerExit} does not fire once per exit — Swoole re-fires it for the
 * whole `reload_async` drain window, so a handler that enumerates parked coroutines and
 * logs one line each re-states an unchanging fact on every pass. The describing half of
 * that diagnostic was pinned by tests; the emitting half was not, and it is the half that
 * broke.
 *
 * Measured on the dev host before this existed: 97 distinct stuck coroutines produced
 * 110061 log lines, a ratio of 1134 lines per fact. Worker 0 cid 36 emitted 18284
 * identical lines across three seconds — roughly six thousand a second for one coroutine
 * that never moved. Those lines were 96.7% of `app.log`, so the 770 errors and 195
 * unhandled exceptions in the same file sat at a 143:1 disadvantage, and the one thing
 * the diagnostic exists to say — worker 0 cid 51 held its worker hostage for 22 minutes,
 * parked in `file_get_contents` inside `ClassDiscovery` — was unreadable.
 *
 * Plain de-duplication would fix the volume and lose the point: "cid 51 is stuck" and
 * "cid 51 has been stuck for twenty-two minutes" are different facts, and only the second
 * one explains a restart that appears to hang. So the gate is time-based, not tick-based.
 * Ticks are not a clock — the same drain produced 6000 passes per second in one episode
 * and roughly 13 per second in another. Under {@see REALARM_AFTER_SECONDS} the two
 * episodes above become one line and about forty-five, each carrying how long the
 * coroutine has been parked.
 *
 * This is {@see WorkerCrashLoopBreaker}'s reasoning about re-alarming, applied to the
 * drain instead of to crashes — but with the opposite storage constraint. That breaker
 * tallies in a {@see \Swoole\Table} because `WorkerError` fires in the manager and its
 * counts must survive a fork. `WorkerExit` fires in the worker, whose process ends at the
 * end of the very episode being tracked, so plain instance state is not just sufficient
 * but correct: one reporter is built before `start()`, each worker forks its own copy,
 * and nothing needs clearing because nothing outlives the exit.
 */
final class WorkerExitDrainReporter
{
    /**
     * How long a coroutine already named in the log stays quiet before it may be named
     * again. Short enough that a hostage worker keeps saying so while an operator is
     * watching a restart, long enough that the drain cannot outrun the log.
     */
    public const REALARM_AFTER_SECONDS = 30.0;

    /** @var array<int, float> cid => when it was first seen refusing cancellation */
    private array $firstSeenAt = [];

    /** @var array<int, float> cid => when a line was last emitted for it */
    private array $lastReportedAt = [];

    private int $ticks = 0;

    private int $suppressed = 0;

    /**
     * Filter one drain pass down to the coroutines that have earned a line.
     *
     * @param array<int, string> $stubborn cid => where the coroutine is parked
     * @param float              $now      MONOTONIC seconds; the caller owns the clock, and
     *                                     must not hand this a wall clock: a backwards jump
     *                                     would yield negative ages and stall the re-alarm
     *
     * @return list<array{cid: int, where: string, stuck_for: float, repeat: bool}>
     */
    public function report(array $stubborn, float $now): array
    {
        ++$this->ticks;
        $due = [];

        foreach ($stubborn as $cid => $where) {
            $cid = (int) $cid;
            $firstSeen = $this->firstSeenAt[$cid] ??= $now;
            $lastReported = $this->lastReportedAt[$cid] ?? null;

            if ($lastReported !== null && ($now - $lastReported) < self::REALARM_AFTER_SECONDS) {
                ++$this->suppressed;
                continue;
            }

            $this->lastReportedAt[$cid] = $now;
            $due[] = [
                'cid' => $cid,
                'where' => $where,
                // Zero on the first sighting, and the whole point on every later one.
                'stuck_for' => round($now - $firstSeen, 3),
                'repeat' => $lastReported !== null,
            ];
        }

        return $due;
    }

    /**
     * One closing line for the whole episode, or null when the drain was clean.
     *
     * Emitted from `WorkerStop`, the one hook that runs after the drain is actually over.
     * It is what makes the suppression honest: an operator can see how many lines were
     * withheld and decide whether {@see REALARM_AFTER_SECONDS} is hiding something.
     *
     * @return array{drain_ticks: int, coroutines: int, suppressed_lines: int, longest_cid: int, held_for_seconds: float}|null
     */
    public function summary(float $now): ?array
    {
        if ($this->firstSeenAt === []) {
            return null;
        }

        $longestCid = 0;
        $longestHeld = 0.0;
        foreach ($this->firstSeenAt as $cid => $firstSeen) {
            $held = $now - $firstSeen;
            if ($held >= $longestHeld) {
                $longestHeld = $held;
                $longestCid = $cid;
            }
        }

        return [
            'drain_ticks' => $this->ticks,
            'coroutines' => count($this->firstSeenAt),
            'suppressed_lines' => $this->suppressed,
            'longest_cid' => $longestCid,
            'held_for_seconds' => round($longestHeld, 3),
        ];
    }
}
