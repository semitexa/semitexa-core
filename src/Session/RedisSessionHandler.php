<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * Reads/writes session data to Redis via a coroutine-safe connection pool.
 *
 * Each operation borrows a connection from the pool and returns it immediately
 * after the command completes. This prevents protocol interleaving under
 * concurrent Swoole coroutines.
 */
final class RedisSessionHandler implements SessionHandlerInterface
{
    private const DEFAULT_LIFETIME = 3600;
    private const KEY_PREFIX = 'semitexa_session:';

    private RedisConnectionPool $pool;

    public function __construct(RedisConnectionPool $pool)
    {
        $this->pool = $pool;
    }

    public function read(string $sessionId): array
    {
        $key = self::KEY_PREFIX . $sessionId;

        $raw = $this->pool->withConnection(
            static fn ($redis): mixed => $redis->get($key)
        );

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = self::DEFAULT_LIFETIME): void
    {
        $key = self::KEY_PREFIX . $sessionId;
        $raw = json_encode(
            $data,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($lifetimeSeconds > 0) {
            $this->pool->withConnection(
                static fn ($redis): mixed => $redis->setex($key, $lifetimeSeconds, $raw)
            );
        } else {
            $this->pool->withConnection(
                static fn ($redis): mixed => $redis->set($key, $raw)
            );
        }
    }

    public function destroy(string $sessionId): void
    {
        $this->pool->withConnection(
            static fn ($redis): mixed => $redis->del(self::KEY_PREFIX . $sessionId)
        );
    }
}
