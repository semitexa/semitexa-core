<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\WorkerState;

/**
 * The store this worker's limits count into. A server installs the shared
 * one at worker start; until then, and in the CLI, counters live in this
 * process (InMemoryRateLimitStore), which only a single worker can rely on.
 */
#[AsService]
final class RateLimitStorage
{
    #[WorkerState('The one store this worker counts into, installed at worker start by InstallRateLimitStoreListener; the store keys its counters itself, and no request chooses it.')]
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
