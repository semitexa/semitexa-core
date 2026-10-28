<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * Per-queue, per-minute counters that every worker adds to and any process
 * reads (queue:stats). A worker runs in its own container, so the store must
 * be one they share: Redis, or a file on a single host.
 */
interface QueueStatsStoreInterface
{
    /** @param array<string, int> $deltas field => amount to add, for one queue and one minute (unix time / 60) */
    public function add(string $queue, int $minute, array $deltas): void;

    /** @return array<string, int> every field summed over the minutes $from..$to (inclusive) */
    public function sum(string $queue, int $fromMinute, int $toMinute): array;

    /** Where the counters live, for queue:stats and the doctor check. */
    public function describe(): string;
}
