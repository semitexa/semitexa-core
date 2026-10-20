<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsService;

/**
 * The store this worker's limits count into. A server installs the shared
 * one at worker start; until then, and in the CLI, counters live in this
 * process (InMemoryRateLimitStore), which only a single worker can rely on.
 */
#[AsService]
final class RateLimitStorage
{
    private ?RateLimitStoreInterface $store = null;

    public function store(): RateLimitStoreInterface
    {
        return $this->store ??= new InMemoryRateLimitStore();
    }

    public function use(RateLimitStoreInterface $store): void
    {
        $this->store = $store;
    }
}
