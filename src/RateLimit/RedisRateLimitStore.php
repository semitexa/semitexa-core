<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * Counters in Redis, for limits that must hold across servers. One script per
 * hit: INCR the current window, give a new counter its expiry, read the
 * previous window — no other client can land between the steps.
 *
 * Redis unreachable (its circuit open, a timeout): the hit is not counted and
 * the limiter lets the request through. A rate limit that took the site down
 * with Redis would be the bigger outage.
 */
final class RedisRateLimitStore implements RateLimitStoreInterface
{
    public const PREFIX = 'semitexa:rl:';

    private const HIT = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        if current == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
        return {current, tonumber(redis.call('GET', KEYS[2])) or 0}
        LUA;

    private const UNDO = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 1 then return redis.call('DECR', KEYS[1]) end
        return 0
        LUA;

    public function __construct(private readonly RedisConnectionPool $pool) {}

    public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array
    {
        try {
            $result = $this->pool->withConnection(static fn ($redis): mixed => $redis->eval(
                self::HIT,
                2,
                self::PREFIX . $currentKey,
                self::PREFIX . $previousKey,
                (string) max(1, $ttlSeconds),
            ));
        } catch (\Throwable) {
            return null;
        }

        return is_array($result) && count($result) === 2 ? [(int) $result[0], (int) $result[1]] : null;
    }

    public function undo(string $currentKey): void
    {
        try {
            $this->pool->withConnection(static fn ($redis): mixed => $redis->eval(self::UNDO, 1, self::PREFIX . $currentKey));
        } catch (\Throwable) {
            // Not taken back: the refused request counts once more. Nothing to do.
        }
    }
}
