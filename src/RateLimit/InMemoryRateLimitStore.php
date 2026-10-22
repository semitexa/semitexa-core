<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

/**
 * Counters in this process only: right for the CLI and tests, and for a
 * single-worker server. Expired counters are swept at most once a second,
 * and past MAX_COUNTERS a new subject is not counted (a hit returns null,
 * which lets it through) rather than growing without bound.
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    public const MAX_COUNTERS = 100_000;

    /** @var array<string, array{n: int, until: int}> */
    private array $counters = [];

    private int $sweptAt = PHP_INT_MIN;

    public function __construct(private readonly ?\Closure $clock = null) {}

    public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array
    {
        $now = $this->now();
        if ($now !== $this->sweptAt) {
            $this->sweptAt = $now;
            foreach ($this->counters as $key => $counter) {
                if ($counter['until'] <= $now) {
                    unset($this->counters[$key]);
                }
            }
        }
        if (!isset($this->counters[$currentKey]) && count($this->counters) >= self::MAX_COUNTERS) {
            return null;
        }

        $current = ($this->counters[$currentKey]['n'] ?? 0) + 1;
        $this->counters[$currentKey] = ['n' => $current, 'until' => $now + $ttlSeconds];

        return [$current, $this->counters[$previousKey]['n'] ?? 0];
    }

    public function undo(string $currentKey): void
    {
        if (isset($this->counters[$currentKey])) {
            $this->counters[$currentKey]['n'] = max(0, $this->counters[$currentKey]['n'] - 1);
        }
    }

    /** Whether $key is counting here, in a window not yet over. */
    public function holds(string $key): bool
    {
        return isset($this->counters[$key]) && $this->counters[$key]['until'] > $this->now();
    }

    /** $key's count, without counting a request. */
    public function counted(string $key): int
    {
        return $this->holds($key) ? $this->counters[$key]['n'] : 0;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
