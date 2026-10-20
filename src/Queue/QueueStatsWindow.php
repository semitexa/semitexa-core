<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/** A queue's counters over a window, read for people: totals, rate, and estimated percentiles. */
final readonly class QueueStatsWindow
{
    /** @param array<string, int> $sums */
    public function __construct(public array $sums, public int $minutes) {}

    public function count(string $field): int
    {
        return $this->sums[$field] ?? 0;
    }

    public function perMinute(): float
    {
        return $this->minutes > 0 ? ($this->count('processed') + $this->count('dead_lettered') + $this->count('retried')) / $this->minutes : 0.0;
    }

    public function averageMs(string $kind): ?int
    {
        $n = $this->count($kind . '_count');

        return $n > 0 ? (int) round($this->count($kind . '_ms_sum') / $n) : null;
    }

    /**
     * The bucket bound the p-th share of jobs falls under — an upper estimate:
     * "p95 <= 250 ms" means 95 % finished within 250 ms. Null when none ran;
     * 'inf' above the largest bucket.
     */
    public function percentileMs(string $kind, float $p): int|string|null
    {
        $n = $this->count($kind . '_count');
        if ($n === 0) {
            return null;
        }
        $seen = 0;
        foreach ([...QueueStatsRecorder::BUCKETS, 'inf'] as $bound) {
            $seen += $this->count($kind . '_le_' . $bound);
            if ($seen >= $p * $n) {
                return $bound;
            }
        }

        return 'inf';
    }
}
