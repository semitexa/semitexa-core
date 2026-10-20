<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\RateLimit\RedisRateLimitStore;
use Semitexa\Core\Redis\RedisConnectionPool;
use Swoole\Coroutine;

/**
 * The Redis counters on a real Redis: one script counts, expires and reads the
 * previous window, and a refused hit is taken back. Skipped without Redis.
 */
final class RedisRateLimitStoreTest extends TestCase
{
    #[Test]
    public function a_window_counts_reads_the_previous_one_and_expires(): void
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $probe = @fsockopen($host, (int) (getenv('REDIS_PORT') ?: 6379), $errno, $errstr, 1.0);
        if ($probe === false) {
            self::markTestSkipped("No Redis at {$host}");
        }
        fclose($probe);

        $key = 'test:' . bin2hex(random_bytes(6));
        $results = [];
        Coroutine\run(static function () use ($host, $key, &$results): void {
            $pool = new RedisConnectionPool(1, ['host' => $host, 'port' => (int) (getenv('REDIS_PORT') ?: 6379)]);
            $store = new RedisRateLimitStore($pool);

            $results['first'] = $store->hit("60:2:{$key}", "60:1:{$key}", 120);
            $results['second'] = $store->hit("60:2:{$key}", "60:1:{$key}", 120);
            $store->undo("60:2:{$key}");
            $results['next'] = $store->hit("60:3:{$key}", "60:2:{$key}", 120);
            $results['ttl'] = $pool->withConnection(static fn ($redis): int => (int) $redis->ttl(RedisRateLimitStore::PREFIX . "60:3:{$key}"));
            $store->undo("60:9:{$key}");
            $results['undoMissing'] = $pool->withConnection(static fn ($redis): int => (int) $redis->exists(RedisRateLimitStore::PREFIX . "60:9:{$key}"));
            $pool->withConnection(static fn ($redis): mixed => $redis->del([
                RedisRateLimitStore::PREFIX . "60:2:{$key}",
                RedisRateLimitStore::PREFIX . "60:3:{$key}",
            ]));
        });

        self::assertSame([1, 0], $results['first']);
        self::assertSame([2, 0], $results['second']);
        self::assertSame([1, 1], $results['next'], 'the undone hit is gone from the previous window');
        self::assertGreaterThan(60, $results['ttl']);
        self::assertSame(0, $results['undoMissing'], 'taking back an expired counter does not create a negative one');
    }
}
