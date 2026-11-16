<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Connection\ConnectionInterface;
use Predis\Response\ServerException;
use Semitexa\Core\Redis\RedisConnectionPool;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;

/**
 * tk-cancelled-ping-returns-dirty-redis-client: a client idle for a second is
 * PINGed before use. An SSE stream whose browser left is cancelled with
 * Coroutine::cancel($cid, true), which throws CanceledException where the
 * coroutine waits — during that PING too. The pool put such a client back, its
 * PONG still unread: the next borrower read the PONG as its answer and left its
 * own answer for the borrower after (reported as guest sessions and CSRF
 * failures; at worst another visitor's session). A client goes back only when
 * Redis answered whole.
 *
 * No Redis is needed: the clients are doubles, and the pool's fresh clients
 * connect only on a first command, which nothing here sends.
 */
final class InterruptedPingDoesNotReturnTheClientTest extends TestCase
{
    #[Test]
    public function a_client_whose_ping_was_cancelled_is_closed_and_replaced(): void
    {
        $outcome = $this->borrowAfterAPingThat(new CanceledException('cancelled while the PONG was in flight'));

        self::assertSame(CanceledException::class, $outcome['thrown']);
        self::assertSame(1, $outcome['disconnects'], 'the interrupted client is closed');
        self::assertSame(1, $outcome['pooled'], 'a fresh client took its place in the pool');
        self::assertFalse($outcome['nextIsTheSameClient'], 'the next borrower gets a fresh client, not the one with a PONG on its socket');
    }

    #[Test]
    public function a_client_redis_answered_with_an_error_goes_back_to_the_pool(): void
    {
        $outcome = $this->borrowAfterAPingThat(new ServerException('LOADING Redis is loading the dataset in memory'));

        self::assertSame(ServerException::class, $outcome['thrown']);
        self::assertSame(0, $outcome['disconnects'], 'an error reply was read whole: the socket is in step');
        self::assertSame(1, $outcome['pooled'], 'the client is back in the pool');
        self::assertTrue($outcome['nextIsTheSameClient'], 'so the client is kept, not lost until the worker restarts');
    }

    /** @return array{thrown: ?string, disconnects: int, pooled: int, nextIsTheSameClient: bool} */
    private function borrowAfterAPingThat(\Throwable $pingFailure): array
    {
        $disconnects = 0;
        $client = $this->idleClientWhosePingThrows($pingFailure, $disconnects);
        $outcome = ['thrown' => null, 'disconnects' => 0, 'pooled' => -1, 'nextIsTheSameClient' => false];
        $failure = null;

        Coroutine\run(function () use ($client, &$disconnects, &$outcome, &$failure): void {
            try {
                $pool = new RedisConnectionPool(1, ['host' => '127.0.0.1', 'port' => 6379]);
                $pool->boot();
                $pool->get(); // the pool's own client out of the way
                $pool->put($client);
                // Idle long enough to be PINGed before its next use.
                $returnedAt = (new \ReflectionProperty(RedisConnectionPool::class, 'returnedAt'))->getValue($pool);
                $returnedAt[$client] = microtime(true) - 10.0;

                try {
                    $pool->withConnection(static fn (): string => 'never reached: the PING failed first');
                } catch (\Throwable $e) {
                    $outcome['thrown'] = $e::class;
                }

                $outcome['disconnects'] = $disconnects;
                // Counted before the next borrow: with nothing pooled, get() would
                // wait out its timeout and hand out an extra client, which is not
                // the interrupted one either, and the test would pass for a wrong reason.
                $channel = (new \ReflectionProperty(RedisConnectionPool::class, 'pool'))->getValue($pool);
                $outcome['pooled'] = $channel instanceof Coroutine\Channel ? $channel->length() : -1;
                $outcome['nextIsTheSameClient'] = $pool->get() === $client;
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $outcome;
    }

    private function idleClientWhosePingThrows(\Throwable $pingFailure, int &$disconnects): ClientInterface
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);

        $client = $this->createMock(ClientInterface::class);
        $client->method('getConnection')->willReturn($connection);
        $client->method('__call')->willReturnCallback(static function (string $command) use ($pingFailure): never {
            if (strtolower($command) !== 'ping') {
                throw new \LogicException('only PING is expected, got ' . $command);
            }
            throw $pingFailure;
        });
        $client->method('disconnect')->willReturnCallback(static function () use (&$disconnects): void {
            $disconnects++;
        });

        return $client;
    }
}
