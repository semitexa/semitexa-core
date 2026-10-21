<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Attribute\WorkerState;

/**
 * Per queue, over the last $windowMinutes: done, retried, dead-lettered, the
 * rate, wait and run time (average, p50, p95), and what is waiting now. One
 * source for `queue:stats` and the OS Queues app.
 *
 * A queue its transport cannot read has an `error`; counters the store
 * cannot give have a `counters_error` and null counts (unknown, not zero).
 * Neither is an exception that takes the whole report with it, and one does
 * not hide the other: a counter store that is down still leaves the live
 * depth and the dead letters readable.
 */
final class QueueStatsReport
{
    #[WorkerState('The counter store, resolved once per worker: opening it on every page load opened a Redis connection each time.')]
    private static ?QueueStatsStoreInterface $store = null;

    #[WorkerState('Whether $store was resolved (it may resolve to null: QUEUE_STATS_STORE=off).')]
    private static bool $storeResolved = false;

    /**
     * @return array{store: string, queues: list<array{transport: string, queue: string, processed: int|null, retried: int|null, dead_lettered: int|null, per_minute: float|null, wait_ms: array{avg: int|null, p50: int|string|null, p95: int|string|null}, run_ms: array{avg: int|null, p50: int|string|null, p95: int|string|null}, waiting: int|null, oldest_waiting_seconds: int|null, error: string|null, counters_error: string|null}>}
     */
    public static function build(QueueInspection $inspection, int $windowMinutes, ?string $transport = null, ?string $queue = null): array
    {
        if (!self::$storeResolved) {
            self::$store = QueueStatsStores::fromEnvironment();
            self::$storeResolved = true;
        }
        $store = self::$store;
        $now = intdiv(time(), 60);
        $rows = [];
        foreach ($inspection->targets($transport, $queue) as $target) {
            $inspector = $target['inspector'];
            $counters = [];
            $waiting = $oldest = $error = $countersError = null;
            try {
                $counters = $store?->sum($target['queue'], $now - $windowMinutes + 1, $now) ?? [];
            } catch (\Throwable $e) {
                $countersError = $e->getMessage();
            }
            try {
                $waiting = $inspector?->depth($target['queue']);
                $oldest = $inspector?->oldestAgeSeconds($target['queue']);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $stats = new QueueStatsWindow($counters, $windowMinutes);
            $known = $countersError === null;
            $rows[] = [
                'transport' => $target['transport'],
                'queue' => $target['queue'],
                'processed' => $known ? $stats->count('processed') : null,
                'retried' => $known ? $stats->count('retried') : null,
                'dead_lettered' => $known ? $stats->count('dead_lettered') : null,
                'per_minute' => $known ? round($stats->perMinute(), 2) : null,
                'wait_ms' => ['avg' => $stats->averageMs('wait'), 'p50' => $stats->percentileMs('wait', 0.5), 'p95' => $stats->percentileMs('wait', 0.95)],
                'run_ms' => ['avg' => $stats->averageMs('run'), 'p50' => $stats->percentileMs('run', 0.5), 'p95' => $stats->percentileMs('run', 0.95)],
                'waiting' => $waiting,
                'oldest_waiting_seconds' => $oldest,
                'error' => $error,
                'counters_error' => $countersError,
            ];
        }

        return ['store' => $store?->describe() ?? 'off', 'queues' => $rows];
    }

    /** A duration as the reports print it: "—" for none, ">30 s" past the last bucket. */
    public static function ms(int|string|null $value): string
    {
        return match (true) {
            $value === null => '—',
            $value === 'inf' => '>30 s',
            default => $value . ' ms',
        };
    }

    /** For a test that changes QUEUE_STATS_STORE. */
    public static function forgetStore(): void
    {
        self::$store = null;
        self::$storeResolved = false;
    }
}
