<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

/**
 * Counters for RateLimiter, one per (bucket, subject, window). Shared by every
 * worker that must agree on a limit: a per-worker count lets N workers admit
 * N times the limit.
 */
interface RateLimitStoreInterface
{
    /**
     * Count one request in $currentKey and read $previousKey, atomically for
     * the current counter.
     *
     * @param int $ttlSeconds how long a counter must outlive its window
     * @return array{0: int, 1: int}|null [current after this hit, previous];
     *         null when the store could not count (full, unreachable)
     */
    public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array;

    /** Take back a hit that was refused, so a refused request does not count. */
    public function undo(string $currentKey): void;
}
