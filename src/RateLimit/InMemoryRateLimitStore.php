<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

/**
 * Counters in this process only: right for the CLI and tests, and for a
 * single-worker server. Expired counters go on the next hit after them.
 */
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, array{n: int, until: int}> */
    private array $counters = [];

    public function __construct(private readonly ?\Closure $clock = null) {}

    public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array
    {
        $now = $this->now();
        foreach ($this->counters as $key => $counter) {
            if ($counter['until'] <= $now) {
                unset($this->counters[$key]);
            }
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

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
