<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Redis\RedisSharedPool;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Core\Server\Lifecycle\WorkerTimerRegistry;
use Swoole\Timer;

/**
 * Gives this worker's RateLimitStorage the shared store RATE_LIMIT_STORE asks
 * for (RateLimitStoreChoice), and has worker 0 sweep the shared table every
 * five seconds: one sweeper is enough for a table every worker writes.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartAfterServerBindings->value,
    priority: 0,
    requiresContainer: true,
)]
final class InstallRateLimitStoreListener implements ServerLifecycleListenerInterface
{
    /** Often enough for one-second windows: a minute let their rows pile up 60 deep. */
    private const SWEEP_EVERY_MS = 5_000;

    public function handle(ServerLifecycleContext $context): void
    {
        $choice = RateLimitStoreChoice::configured();
        if ($choice === RateLimitStoreChoice::Memory) {
            return;
        }

        $container = $context->container;
        if ($container === null) {
            return;
        }
        $storage = $container->get(RateLimitStorage::class);
        if (!$storage instanceof RateLimitStorage) {
            return;
        }

        $redis = null;
        if ($choice->wantsRedis() && $container->has(RedisSharedPool::class)) {
            $shared = $container->get(RedisSharedPool::class);
            $redis = $shared instanceof RedisSharedPool ? $shared->poolOrNull() : null;
        }
        if ($redis !== null) {
            $storage->use(new RedisRateLimitStore($redis));

            return;
        }

        $table = $context->bootstrapState?->get(RateLimitStoreChoice::TABLE_STATE_KEY);
        if (!$table instanceof SwooleTableRateLimitStore) {
            return;
        }
        $storage->use($table);

        if ($context->workerId === 0 && ($context->server->taskworker ?? false) !== true && class_exists(Timer::class, false)) {
            WorkerTimerRegistry::register(Timer::tick(self::SWEEP_EVERY_MS, static function () use ($table): void {
                $table->sweep();
            }));
        }
    }
}
