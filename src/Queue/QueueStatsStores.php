<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Environment;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Redis\RedisSharedPool;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Where queue counters live, from QUEUE_STATS_STORE: `auto` (default) is Redis
 * when REDIS_HOST is set — any container reads it — else files under
 * var/queue-stats, shared only on one host; `redis`, `file`, or `off`.
 */
final class QueueStatsStores
{
    public static function fromEnvironment(): ?QueueStatsStoreInterface
    {
        $choice = strtolower(trim((string) Environment::getEnvValue('QUEUE_STATS_STORE', 'auto')));
        if ($choice === 'off') {
            return null;
        }

        if (!in_array($choice, ['auto', 'redis', 'file'], true)) {
            StaticLoggerBridge::warning('queue', 'QUEUE_STATS_STORE is not auto, redis, file or off; using auto', ['value' => $choice]);
        }

        // Its own one-connection pool: a queue worker runs outside the server
        // and its container, so there is no shared pool to borrow from.
        $redis = RedisSharedPool::fromEnvironment(1);
        if ($choice !== 'file' && $redis->isConfigured()) {
            $pool = $redis->poolOrNull();
            if ($pool !== null) {
                return new RedisQueueStatsStore($pool);
            }
        }
        if ($choice === 'redis') {
            StaticLoggerBridge::warning('queue', 'QUEUE_STATS_STORE=redis but REDIS_HOST is empty: counters go to files, which another container cannot read', []);
        }

        return new FileQueueStatsStore(ProjectRoot::get() . '/var/queue-stats');
    }
}
