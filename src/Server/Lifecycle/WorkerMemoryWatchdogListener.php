<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\CoroutineSite;
use Semitexa\Core\Server\WorkerMemoryWatchdog;
use Semitexa\Core\Support\StandingCoroutines;
use Swoole\Timer;

/**
 * Recycles a worker whose memory crosses SWOOLE_MEMORY_WATCHDOG_RATIO of its
 * memory_limit (default 0.8; 0 turns it off), checked every
 * SWOOLE_MEMORY_WATCHDOG_INTERVAL seconds (default 10).
 *
 * The worker says what it was holding — memory, coroutine count, where its
 * coroutines are parked — and then calls Server::stop() on itself. That is a
 * graceful exit: measured on Swoole 6.2 (2026-10-16) it runs the same
 * WorkerExit drain a reload does (timers cleared, parked coroutines
 * cancelled), the manager starts a fresh worker in its place, requests keep
 * being served by the others, and it is not counted as a crash. Without it the
 * same worker is killed by PHP at the limit, mid-request.
 *
 * Event workers only: a task worker is stopped by its own server.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: false,
)]
final class WorkerMemoryWatchdogListener implements ServerLifecycleListenerInterface
{
    public const DEFAULT_RATIO = 0.8;
    public const DEFAULT_INTERVAL_SECONDS = 10;

    /** How many parked places the report names; the rest are counted. */
    private const REPORTED_SITES = 5;
    /** Backtraces are not free; a worker this large may hold thousands. */
    private const SAMPLED_COROUTINES = 500;

    public function handle(ServerLifecycleContext $context): void
    {
        if (!class_exists(Timer::class, false) || $context->workerId === null) {
            return;
        }
        $server = $context->server;
        if ($server === null || ($server->taskworker ?? false) === true) {
            return;
        }

        $watchdog = WorkerMemoryWatchdog::fromLimit(
            (string) ini_get('memory_limit'),
            self::ratio(),
        );
        if ($watchdog === null) {
            return;
        }

        $workerId = $context->workerId;
        // Local, not static: each worker process arms its own, once per start.
        $leaving = false;
        $timerId = Timer::tick(self::intervalSeconds() * 1000, static function () use ($watchdog, $server, $workerId, &$leaving): void {
            if ($leaving) {
                return;
            }
            $usage = memory_get_usage(true);
            if (!$watchdog->isOver($usage)) {
                return;
            }
            $leaving = true;

            StaticLoggerBridge::warning('lifecycle', 'Worker memory over the watchdog threshold; recycling the worker', [
                'worker_id' => $workerId,
                'memory_bytes' => $usage,
                'peak_bytes' => memory_get_peak_usage(true),
                'threshold_bytes' => $watchdog->thresholdBytes,
                'limit_bytes' => $watchdog->limitBytes,
            ] + self::holding());

            $server->stop();
        });
        WorkerTimerRegistry::register($timerId);
    }

    public static function ratio(): float
    {
        $value = Environment::getEnvValue('SWOOLE_MEMORY_WATCHDOG_RATIO');

        return is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : self::DEFAULT_RATIO;
    }

    public static function intervalSeconds(): int
    {
        $value = Environment::getEnvValue('SWOOLE_MEMORY_WATCHDOG_INTERVAL');
        $seconds = is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : self::DEFAULT_INTERVAL_SECONDS;

        return max(1, $seconds);
    }

    /**
     * What the worker was holding: the operator's first question about a
     * worker that grew, and gone once it restarts.
     *
     * @return array{coroutines: int, standing: list<string>, parked_at: array<string, int>}
     */
    private static function holding(): array
    {
        $current = \Swoole\Coroutine::getCid();
        $live = \Swoole\Coroutine::listCoroutines();
        $cids = [];
        foreach (is_iterable($live) ? $live : [] as $cid) {
            if (is_int($cid) && $cid !== $current) {
                $cids[] = $cid;
            }
        }

        $sites = [];
        foreach (array_slice($cids, 0, self::SAMPLED_COROUTINES) as $cid) {
            $site = CoroutineSite::describe($cid);
            $sites[$site] = ($sites[$site] ?? 0) + 1;
        }
        arsort($sites);
        $reported = array_slice($sites, 0, self::REPORTED_SITES, true);
        $others = array_sum($sites) - array_sum($reported);
        if ($others > 0) {
            $reported['(elsewhere)'] = $others;
        }

        $standing = [];
        foreach (StandingCoroutines::all($cids) as $declared) {
            $standing[] = $declared['label'];
        }

        return ['coroutines' => count($cids), 'standing' => array_values(array_unique($standing)), 'parked_at' => $reported];
    }
}
