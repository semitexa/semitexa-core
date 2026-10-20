<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Swoole\Table;

/**
 * Creates the rate-limit counter table in the master, before the workers fork:
 * a table created in a worker is that worker's alone, and N workers counting
 * apart admit N times every limit.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::PreStart->value,
    priority: 0,
    requiresContainer: false,
)]
final class CreateRateLimitTableListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        if ($context->bootstrapState === null || !class_exists(Table::class, false)) {
            return;
        }
        if (!RateLimitStoreChoice::configured()->wantsTable()) {
            return;
        }

        $context->bootstrapState->set(
            RateLimitStoreChoice::TABLE_STATE_KEY,
            SwooleTableRateLimitStore::create(RateLimitStoreChoice::tableSize()),
        );
    }
}
