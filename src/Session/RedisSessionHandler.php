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
final class RedisSessionHandler implements HeldSessionWriterInterface
{
    private const DEFAULT_LIFETIME = 3600;
    private const KEY_PREFIX = 'semitexa_session:';

    /**
     * SET (with the lifetime ARGV[3], when above 0) only while the key holds a
     * value that is not ARGV[1]. Redis runs a script whole, so no write of
     * another request lands between the comparison and the SET.
     */
    private const WRITE_IF_HELD = <<<'LUA'
        local held = redis.call('GET', KEYS[1])
        if (not held) or held == '' or held == ARGV[1] then return 0 end
        if tonumber(ARGV[3]) > 0 then
            redis.call('SETEX', KEYS[1], ARGV[3], ARGV[2])
        else
            redis.call('SET', KEYS[1], ARGV[2])
        end
        return 1
        LUA;

    private RedisConnectionPool $pool;

    public function __construct(RedisConnectionPool $pool)
    {
        $this->pool = $pool;
    }

    public function read(string $sessionId): array
    {
        $key = self::KEY_PREFIX . $sessionId;

        // A GET is safe to repeat: one dead socket must not make the visitor a guest.
        $raw = $this->pool->withIdempotentRead(
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
        $raw = self::encode($data);

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

    /**
     * One script, so atomic. $unless is compared as the bytes write() stores
     * for it: both go through {@see encode()}.
     */
    public function writeIfHeld(string $sessionId, array $data, int $lifetimeSeconds, array $unless): bool
    {
        $key = self::KEY_PREFIX . $sessionId;
        $raw = self::encode($data);
        $unlessRaw = self::encode($unless);

        $wrote = $this->pool->withConnection(
            static fn ($redis): mixed => $redis->eval(self::WRITE_IF_HELD, 1, $key, $unlessRaw, $raw, (string) max(0, $lifetimeSeconds))
        );

        return $wrote === 1;
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode(
            $data,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public function destroy(string $sessionId): void
    {
        $this->pool->withConnection(
            static fn ($redis): mixed => $redis->del(self::KEY_PREFIX . $sessionId)
        );
    }
}
