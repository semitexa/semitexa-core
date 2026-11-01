<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Semitexa\Core\Redis\RedisConnectionPool;
use Swoole\Coroutine;
use Semitexa\Core\Environment;

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
        $host = self::reachableRedisHost();

        $results = [];
        $killed = [];
        $open = null;
        $failure = null;
        Coroutine\run(function () use ($host, &$results, &$killed, &$open, &$failure): void {
            try {
                $pool = new RedisConnectionPool(3, self::redisConfig($host));
                // Connect all three pooled clients, and note who they are. A
                // child coroutine's exception would end only that coroutine and
                // skip done(): kept here and rethrown, it fails the test instead.
                $ids = [];
                $childFailures = [];
                $wg = new Coroutine\WaitGroup();
                for ($i = 0; $i < 3; $i++) {
                    $wg->add();
                    Coroutine::create(function () use ($pool, $wg, &$ids, &$childFailures): void {
                        try {
                            $pool->withConnection(static function (\Predis\ClientInterface $redis) use (&$ids): void {
                                // Through the interface the pool hands out: an
                                // error reply throws here instead of becoming an id.
                                $ids[] = $redis->executeCommand($redis->createCommand('CLIENT', ['ID']));
                                Coroutine::sleep(0.05);
                            });
                        } catch (\Throwable $e) {
                            $childFailures[] = $e;
                        } finally {
                            $wg->done();
                        }
                    });
                }
                $wg->wait();
                if ($childFailures !== []) {
                    throw $childFailures[0];
                }

                // Only three different integers are three live connections.
                if (count($ids) !== 3 || count(array_unique($ids)) !== 3 || array_filter($ids, 'is_int') !== $ids) {
                    throw new \RuntimeException('Expected three distinct CLIENT IDs, got ' . var_export($ids, true));
                }

                // The restart, as the pooled sockets see it; a restart takes
                // longer than the idle-PING threshold. Only this pool's three
                // connections: a Redis the app also uses keeps its own clients.
                $admin = new Client(self::redisConfig($host));
                // executeRaw answers an error as a string instead of throwing:
                // the integers are checked after the coroutine.
                foreach ($ids as $id) {
                    $killed[] = $admin->executeRaw(['CLIENT', 'KILL', 'ID', (string) $id]);
                }
                Coroutine::sleep(1.1);

                for ($i = 0; $i < 3; $i++) {
                    $pong = $pool->withConnection(static fn ($redis): mixed => $redis->ping());
                    $results[] = is_scalar($pong) || $pong instanceof \Stringable ? (string) $pong : get_debug_type($pong);
                }
                $open = $pool->isCircuitOpen();
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        self::assertNull($failure, $failure?->getMessage() ?? '');
        // CLIENT KILL ID answers the number of clients it closed.
        self::assertSame([1, 1, 1], $killed, 'each pooled connection must have been killed');
        self::assertSame(['PONG', 'PONG', 'PONG'], $results);
        self::assertFalse($open, 'Redis is up: the breaker must not have opened');
    }

    #[Test]
    public function a_failed_callback_is_never_run_twice(): void
    {
        $host = self::reachableRedisHost();

        $runs = 0;
        $failure = null;
        Coroutine\run(function () use ($host, &$runs, &$failure): void {
            $pool = new RedisConnectionPool(1, self::redisConfig($host));
            $pool->withConnection(static fn ($redis): mixed => $redis->ping());
            try {
                // A lost response after the command ran looks exactly like this:
                // replaying it would apply an INCR or RPUSH twice.
                $pool->withConnection(function () use (&$runs): never {
                    $runs++;
                    throw new \Predis\Connection\ConnectionException(
                        $this->createStub(\Predis\Connection\NodeConnectionInterface::class),
                        'Error while reading line from the server.',
                    );
                });
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        self::assertInstanceOf(\Predis\Connection\ConnectionException::class, $failure);
        self::assertSame(1, $runs);
    }

    // verify:accept-test-change the Redis reachability guard moved, unchanged, into this helper shared by both tests; every assertion stayed and one test was added
    private static function reachableRedisHost(): string
    {
        // Not configured is a skip; configured and silent fails: a skip there
        // left a stack that could not reach its Redis green over these checks.
        // Read as the application reads it (RedisSharedPool::fromEnvironment):
        // a REDIS_HOST only in .env is configured, not unset.
        $host = Environment::getEnvValue('REDIS_HOST');
        if ($host === null || $host === '') {
            self::markTestSkipped('REDIS_HOST not configured — the test requires real Redis');
        }
        $probe = @fsockopen($host, (int) Environment::getEnvValue('REDIS_PORT', '6379'), $errno, $errstr, 1.0);
        if ($probe === false) {
            self::fail("REDIS_HOST={$host} is configured, but nothing answers on its port: {$errstr}");
        }
        fclose($probe);

        return $host;
    }

    /**
     * The connection as the application makes it (RedisSharedPool::fromEnvironment):
     * scheme, host, port and password, so a protected or non-default Redis
     * configured in .env is reached the same way.
     *
     * @return array{scheme: string, host: string, port: int, password: string}
     */
    private static function redisConfig(string $host): array
    {
        return [
            'scheme' => Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp',
            'host' => $host,
            'port' => (int) Environment::getEnvValue('REDIS_PORT', '6379'),
            'password' => Environment::getEnvValue('REDIS_PASSWORD') ?? '',
        ];
    }
}
