<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Semitexa\Core\Redis\RedisConnectionPool;
use Swoole\Coroutine;

/**
 * After Redis restarts, every connection waiting in the pool is dead.
 *
 * Each would fail its next caller with "Error while reading line from the
 * server", open the circuit breaker, and — one probe per doubling window —
 * keep sessions down for minutes while Redis was already back (review of
 * core#182). CLIENT KILL plays the restart against the dev stack's Redis.
 */
final class RedisPoolRestartTest extends TestCase
{
    #[Test]
    public function the_first_call_after_a_restart_succeeds_and_the_breaker_stays_closed(): void
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $probe = @fsockopen($host, (int) (getenv('REDIS_PORT') ?: 6379), $errno, $errstr, 1.0);
        if ($probe === false) {
            self::markTestSkipped("No Redis at {$host}");
        }
        fclose($probe);

        $results = [];
        $open = null;
        $failure = null;
        Coroutine\run(function () use ($host, &$results, &$open, &$failure): void {
            try {
                $pool = new RedisConnectionPool(3, ['host' => $host, 'port' => (int) (getenv('REDIS_PORT') ?: 6379)]);
                // Connect all three pooled clients.
                $wg = new Coroutine\WaitGroup();
                for ($i = 0; $i < 3; $i++) {
                    $wg->add();
                    Coroutine::create(function () use ($pool, $wg): void {
                        $pool->withConnection(static function ($redis): void {
                            $redis->ping();
                            Coroutine::sleep(0.05);
                        });
                        $wg->done();
                    });
                }
                $wg->wait();

                // The restart, as the pooled sockets see it.
                (new Client(['host' => $host]))->executeRaw(['CLIENT', 'KILL', 'TYPE', 'normal', 'SKIPME', 'yes']);

                for ($i = 0; $i < 3; $i++) {
                    $results[] = (string) $pool->withConnection(static fn ($redis): mixed => $redis->ping());
                }
                $open = $pool->isCircuitOpen();
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        self::assertNull($failure, $failure?->getMessage() ?? '');
        self::assertSame(['PONG', 'PONG', 'PONG'], $results);
        self::assertFalse($open, 'Redis is up: the breaker must not have opened');
    }
}
