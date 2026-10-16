<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Response\ServerException;
use Semitexa\Core\Redis\RedisCircuitBreaker;
use Semitexa\Core\Redis\RedisUnavailableException;

/**
 * While Redis is down a call must fail at once, not after a connect or DNS
 * timeout (5 s per page, measured with the container stopped).
 */
final class RedisCircuitBreakerTest extends TestCase
{
    private float $now = 100.0;

    private int $attempts = 0;

    #[Test]
    public function a_connection_failure_opens_it_and_later_calls_fail_without_trying(): void
    {
        $breaker = $this->breaker();
        $this->expectFailure($breaker, ConnectionException::class);

        for ($i = 0; $i < 50; $i++) {
            $this->expectFailure($breaker, RedisUnavailableException::class);
        }

        self::assertSame(1, $this->attempts, 'only the first call reached the network');
        self::assertTrue($breaker->isOpen());
    }

    #[Test]
    public function after_the_cooldown_one_probe_goes_through_and_a_success_closes_it(): void
    {
        $breaker = $this->breaker();
        $this->expectFailure($breaker, ConnectionException::class);

        $this->now += 2.0;
        self::assertSame('PONG', $breaker->call(function (): string {
            $this->attempts++;

            return 'PONG';
        }));

        self::assertFalse($breaker->isOpen());
        self::assertSame('again', $breaker->call(static fn (): string => 'again'));
    }

    #[Test]
    public function a_failed_probe_reopens_it_for_another_cooldown(): void
    {
        $breaker = $this->breaker();
        $this->expectFailure($breaker, ConnectionException::class);
        $this->now += 2.0;
        $this->expectFailure($breaker, ConnectionException::class);
        $this->now += 1.0;

        $this->expectFailure($breaker, RedisUnavailableException::class);
        self::assertSame(2, $this->attempts);
    }

    #[Test]
    public function the_window_doubles_on_each_failed_probe_up_to_the_cap_and_resets_on_success(): void
    {
        $breaker = $this->breaker();
        $this->expectFailure($breaker, ConnectionException::class);
        $windows = [];
        for ($i = 0; $i < 6; $i++) {
            $start = $this->now;
            $before = $this->attempts;
            while ($this->attempts === $before) {
                $this->now += 0.5;
                // Refused (RedisUnavailable) until the window ends, then a failed probe.
                $this->expectFailure($breaker, \Throwable::class);
            }
            $windows[] = $this->now - $start;
        }

        self::assertSame([2.0, 4.0, 8.0, 16.0, 30.0, 30.0], $windows);

        $this->now += 30.0;
        $breaker->call(static fn (): string => 'PONG');

        // A new outage starts from the first window and doubles from there,
        // not from where the previous outage left off (30 s).
        $this->expectFailure($breaker, ConnectionException::class);
        $this->now += 2.0;
        $this->expectFailure($breaker, ConnectionException::class);
        $this->now += 3.5;
        $this->expectFailure($breaker, RedisUnavailableException::class);
        $this->now += 0.5;
        $breaker->call(static fn (): string => 'PONG');
        self::assertFalse($breaker->isOpen());
    }

    #[Test]
    public function calls_already_in_flight_when_redis_failed_do_not_lengthen_the_window(): void
    {
        $breaker = $this->breaker();
        // The outer call started while the breaker was closed; the inner one
        // fails first and opens it; then the outer one fails too — as five
        // concurrent requests do when Redis goes away under them.
        try {
            $breaker->call(function () use ($breaker): never {
                $this->expectFailure($breaker, ConnectionException::class);
                throw new ConnectionException($this->createMock(NodeConnectionInterface::class), 'in flight');
            });
        } catch (ConnectionException) {
        }

        $this->now += 2.0;
        self::assertSame('PONG', $breaker->call(static fn (): string => 'PONG'), 'the first window is still 2 s');
    }

    #[Test]
    public function an_error_redis_answers_is_not_an_outage(): void
    {
        $breaker = $this->breaker();
        try {
            $breaker->call(static fn () => throw new ServerException('WRONGTYPE Operation against a key holding the wrong kind of value'));
            self::fail('the server error must propagate');
        } catch (ServerException) {
        }

        self::assertFalse($breaker->isOpen());
    }

    #[Test]
    public function the_refusal_carries_the_failure_that_opened_it(): void
    {
        $breaker = $this->breaker();
        $this->expectFailure($breaker, ConnectionException::class);

        try {
            $breaker->call(static fn (): null => null);
            self::fail('expected a refusal');
        } catch (RedisUnavailableException $e) {
            self::assertInstanceOf(ConnectionException::class, $e->getPrevious());
            self::assertStringContainsString('getaddrinfo for redis failed', $e->getMessage());
        }
    }

    private function breaker(): RedisCircuitBreaker
    {
        return new RedisCircuitBreaker(2.0, 10.0, fn (): float => $this->now);
    }

    /** @param class-string<\Throwable> $expected */
    private function expectFailure(RedisCircuitBreaker $breaker, string $expected): void
    {
        try {
            $breaker->call(function (): never {
                $this->attempts++;

                throw new ConnectionException(
                    $this->createMock(NodeConnectionInterface::class),
                    'php_network_getaddresses: getaddrinfo for redis failed',
                );
            });
            self::fail("expected {$expected}");
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e);
        }
    }
}
