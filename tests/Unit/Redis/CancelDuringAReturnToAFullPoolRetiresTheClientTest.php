<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Connection\ConnectionInterface;
use Semitexa\Core\Redis\RedisCircuitBreaker;
use Semitexa\Core\Redis\RedisConnectionPool;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;

/**
 * tk-cancel-during-full-pool-put-leaks-an-extra (Fable's review of core#240):
 * a client returned to a FULL pool waits up to 50 ms in the push, and is retired
 * when no room comes. A cancel inside that wait (an SSE stream whose browser
 * left is cancelled with Coroutine::cancel(..., true)) threw past retire(): the
 * over-cap client was never closed and the pool kept counting it, one step
 * nearer to "exhausted" for good.
 *
 * No Redis is needed: every client is a double, made by the pool's factory.
 */
final class CancelDuringAReturnToAFullPoolRetiresTheClientTest extends TestCase
{
    #[Test]
    public function a_cancel_while_put_waits_on_a_full_pool_retires_the_client_and_goes_on(): void
    {
        $outcome = $this->cancelledInTheWait(static function (RedisConnectionPool $pool, ClientInterface $overCap): void {
            $pool->put($overCap);
        });

        self::assertSame([
            'cancelled' => true,
            'thrown' => CanceledException::class,
            'extra' => 0,
            'disconnects' => ['over-cap' => 1],
        ], $outcome);
    }

    #[Test]
    public function a_cancel_while_a_replacement_waits_on_a_full_pool_retires_the_replacement(): void
    {
        // replace() closes a client that may be out of step and pushes a fresh one
        // in its place; on a full pool the fresh one is the client over the cap.
        $outcome = $this->cancelledInTheWait(static function (RedisConnectionPool $pool, ClientInterface $overCap): void {
            (new \ReflectionMethod(RedisConnectionPool::class, 'replace'))->invoke($pool, $overCap);
        });

        self::assertSame([
            'cancelled' => true,
            'thrown' => null, // replace() runs on its caller's way out, which throws its own error
            'extra' => 0,
            'disconnects' => ['over-cap' => 1, 'replacement' => 1],
        ], $outcome);
    }

    /**
     * A pool of one, full, with one client out over the cap: the act waits in
     * the push, and is cancelled there.
     *
     * @param \Closure(RedisConnectionPool, ClientInterface): void $act
     * @return array{cancelled: bool, thrown: ?string, extra: mixed, disconnects: array<string, int>}
     */
    private function cancelledInTheWait(\Closure $act): array
    {
        $disconnects = [];
        $made = 0;
        $factory = function () use (&$made, &$disconnects): ClientInterface {
            return $this->client(++$made === 1 ? 'pooled' : 'replacement', $disconnects);
        };
        $outcome = ['cancelled' => false, 'thrown' => null, 'extra' => null, 'disconnects' => []];
        $failure = null;

        Coroutine\run(function () use ($factory, $act, &$outcome, &$failure, &$disconnects): void {
            try {
                $pool = new RedisConnectionPool(1, [], new RedisCircuitBreaker(), $factory);
                $pool->boot(); // one client in a pool of one: full
                $extra = new \ReflectionProperty(RedisConnectionPool::class, 'extra');
                $extra->setValue($pool, 1); // a timed-out borrow made one more
                $overCap = $this->client('over-cap', $disconnects);

                $done = new Coroutine\Channel(1);
                $waiter = Coroutine::create(function () use ($pool, $act, $overCap, &$outcome, $done): void {
                    try {
                        $act($pool, $overCap);
                    } catch (\Throwable $e) {
                        $outcome['thrown'] = $e::class;
                    } finally {
                        $done->push(true);
                    }
                });
                // The waiter ran until the push suspended it on the full channel. The
                // second argument makes the cancel throw inside it, as an SSE stream's
                // is; the Swoole stub PHPStan sees omits it.
                /** @phpstan-ignore-next-line arguments.count */
                $outcome['cancelled'] = is_int($waiter) && Coroutine::cancel($waiter, true);
                $done->pop(1.0);

                $outcome['extra'] = $extra->getValue($pool);
                ksort($disconnects);
                $outcome['disconnects'] = $disconnects;
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $outcome;
    }

    /** @param array<string, int> $disconnects */
    private function client(string $name, array &$disconnects): ClientInterface
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $client = $this->createMock(ClientInterface::class);
        $client->method('getConnection')->willReturn($connection);
        $client->method('disconnect')->willReturnCallback(static function () use ($name, &$disconnects): void {
            $disconnects[$name] = ($disconnects[$name] ?? 0) + 1;
        });

        return $client;
    }
}
