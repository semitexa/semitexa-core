<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Log\FallbackErrorLogger;

/**
 * What a worker did, per queue and minute: processed, dead-lettered, retried,
 * and how long each job waited and ran (sums and fixed buckets, from which
 * queue:stats estimates p50/p95). Written as each job finishes: a worker sits
 * blocked in its transport between jobs and is stopped by a signal it does not
 * handle, so a buffer held for later was a buffer lost — a first version kept
 * four of five jobs in one and never wrote them. One pipelined round trip per
 * job. A store that fails loses that job's counts; the job is never failed for it.
 */
final class QueueStatsRecorder
{
    /** Bucket upper bounds in ms; the last bucket is everything above. */
    public const BUCKETS = [10, 50, 250, 1000, 5000, 30000];

    private float $reportedAt = 0.0;

    public function __construct(
        private readonly QueueStatsStoreInterface $store,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * From QUEUE_STATS_STORE; null when it is off or cannot be opened (a
     * worker runs without counters rather than not at all). Opening reads
     * configuration only, so a failure here is not transient — Redis being
     * away surfaces in record() — and the worker caching the null is right.
     */
    public static function fromEnvironment(): ?self
    {
        try {
            $store = QueueStatsStores::fromEnvironment();
        } catch (\Throwable $e) {
            // Said once: the worker counts nothing until it restarts, and
            // queue:stats shows the missing jobs as zeros with no error.
            FallbackErrorLogger::log('Queue statistics store could not be opened; this worker counts no jobs until it restarts', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $store === null ? null : new self($store);
    }

    /** @param 'processed'|'dead_lettered' $outcome */
    public function record(string $queue, string $outcome, bool $retried, ?int $waitMs, int $runMs): void
    {
        $minute = intdiv((int) $this->now(), 60);
        $fields = [$retried ? 'retried' : $outcome => 1, 'run_ms_sum' => $runMs, 'run_le_' . self::bucket($runMs) => 1, 'run_count' => 1];
        if ($waitMs !== null) {
            $fields += ['wait_ms_sum' => $waitMs, 'wait_le_' . self::bucket($waitMs) => 1, 'wait_count' => 1];
        }
        try {
            $this->store->add($queue, $minute, $fields);
        } catch (\Throwable $e) {
            // Once a minute, not once a job, while the store is away.
            if (microtime(true) - $this->reportedAt < 60) {
                return;
            }
            $this->reportedAt = microtime(true);
            FallbackErrorLogger::log('Queue statistics could not be written; this job\'s counts are lost', [
                'queue' => $queue,
                'store' => $this->store->describe(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function bucket(int $ms): string
    {
        foreach (self::BUCKETS as $bound) {
            if ($ms <= $bound) {
                return (string) $bound;
            }
        }

        return 'inf';
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }
}
