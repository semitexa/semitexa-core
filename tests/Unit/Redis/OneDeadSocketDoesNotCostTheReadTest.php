<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Connection\ConnectionException;
use Predis\Connection\ConnectionInterface;
use Predis\Connection\NodeConnectionInterface;
use Semitexa\Core\Redis\RedisCircuitBreaker;
use Semitexa\Core\Redis\RedisConnectionPool;
use Swoole\Coroutine;

/**
 * tk-one-redis-read-error-makes-everyone-a-guest: a session read that hit one
 * dead socket served the visitor a guest session. With the breaker opening on
 * that first failure, every other request of the window got one too, and each
 * sign-in POST in it failed CSRF (consumer production, 2026-11-16). A read safe
 * to repeat is answered by one retry on fresh clients. A write never is: cut off
 * mid-reply, it may have been applied.
 *
 * No Redis is needed: every client is a double, made by the pool's factory.
 */
final class OneDeadSocketDoesNotCostTheReadTest extends TestCase
{
    #[Test]
    public function a_read_that_hits_a_dead_socket_is_answered_by_a_fresh_client(): void
    {
        $outcome = $this->inAPoolWhoseFirstClientIsDead(
            static fn (RedisConnectionPool $pool): mixed => $pool->withIdempotentRead(static fn ($redis): mixed => $redis->get('semitexa_session:abc')),
            freshClientsAnswer: '{"user": 7}',
        );

        self::assertSame('{"user": 7}', $outcome['result']);
        self::assertSame(['get'], $outcome['deadClientCalls'], 'the dead client was asked once, never again');
        self::assertFalse($outcome['open'], 'one failure is not an outage');
    }

    #[Test]
    public function a_read_whose_retry_fails_too_opens_the_breaker(): void
    {
        $outcome = $this->inAPoolWhoseFirstClientIsDead(
            static fn (RedisConnectionPool $pool): mixed => $pool->withIdempotentRead(static fn ($redis): mixed => $redis->get('semitexa_session:abc')),
            freshClientsAnswer: null,
        );

        self::assertSame(ConnectionException::class, $outcome['thrown']);
        self::assertTrue($outcome['open'], 'two failures in a row are an outage: fail fast from here');
    }

    #[Test]
    public function a_write_that_hits_a_dead_socket_is_never_repeated(): void
    {
        $outcome = $this->inAPoolWhoseFirstClientIsDead(
            static fn (RedisConnectionPool $pool): mixed => $pool->withConnection(static fn ($redis): mixed => $redis->setex('semitexa_session:abc', 3600, '{}')),
            freshClientsAnswer: 'OK',
        );

        self::assertSame(ConnectionException::class, $outcome['thrown']);
        self::assertSame(['setex'], $outcome['deadClientCalls']);
        self::assertSame(0, $outcome['freshClientCalls'], 'no fresh client was asked to apply it again');
    }

    /**
     * @param \Closure(RedisConnectionPool): mixed $work
     * @param ?string $freshClientsAnswer what every later client answers; null: they fail too
     * @return array{result: mixed, thrown: ?string, open: bool, deadClientCalls: list<string>, freshClientCalls: int}
     */
    private function inAPoolWhoseFirstClientIsDead(\Closure $work, ?string $freshClientsAnswer): array
    {
        $deadClientCalls = [];
        $freshClientCalls = 0;
        $made = 0;
        $factory = function () use (&$made, &$deadClientCalls, &$freshClientCalls, $freshClientsAnswer): ClientInterface {
            $first = ++$made === 1;

            return $this->client(function (string $command) use ($first, &$deadClientCalls, &$freshClientCalls, $freshClientsAnswer): mixed {
                if ($first) {
                    $deadClientCalls[] = strtolower($command);
                } else {
                    $freshClientCalls++;
                }
                if ($first || $freshClientsAnswer === null) {
                    throw new ConnectionException($this->createMock(NodeConnectionInterface::class), 'Error while reading line from the server.');
                }

                return $freshClientsAnswer;
            });
        };
        $outcome = ['result' => null, 'thrown' => null, 'open' => false, 'deadClientCalls' => [], 'freshClientCalls' => 0];
        $failure = null;

        Coroutine\run(function () use ($factory, $work, &$outcome, &$failure, &$deadClientCalls, &$freshClientCalls): void {
            try {
                $pool = new RedisConnectionPool(1, [], new RedisCircuitBreaker(), $factory);
                $pool->boot();
                try {
                    $outcome['result'] = $work($pool);
                } catch (\Throwable $e) {
                    $outcome['thrown'] = $e::class;
                }
                $outcome['open'] = $pool->isCircuitOpen();
                $outcome['deadClientCalls'] = $deadClientCalls;
                $outcome['freshClientCalls'] = $freshClientCalls;
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $outcome;
    }

    /** @param \Closure(string): mixed $answer */
    private function client(\Closure $answer): ClientInterface
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);

        $client = $this->createMock(ClientInterface::class);
        $client->method('getConnection')->willReturn($connection);
        $client->method('__call')->willReturnCallback(static fn (string $command): mixed => $answer($command));

        return $client;
    }
}
