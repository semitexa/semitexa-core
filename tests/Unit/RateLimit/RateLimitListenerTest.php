<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\RateLimit;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\RateLimit\InMemoryRateLimitStore;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\RateLimit\RateLimitListener;
use Semitexa\Core\RateLimit\RateLimitStorage;
use Semitexa\Core\Request;

#[RateLimit(1, 60)]
final class RateLimitListenerTestPayload
{
}

#[RateLimit(1000, 3600)]
#[RateLimit(1, 60)]
final class RateLimitListenerTwoLimitsPayload
{
}

final class RateLimitListenerTest extends TestCase
{
    #[Test]
    public function a_re_run_of_a_live_region_is_the_request_already_counted(): void
    {
        $listener = self::listener();
        $listener->handle(self::context(reRun: false));

        // A held-open region re-renders on every change: counting each tick
        // would refuse the page its own updates.
        for ($i = 0; $i < 5; $i++) {
            $listener->handle(self::context(reRun: true));
        }

        $this->expectException(RateLimitException::class);
        $listener->handle(self::context(reRun: false));
    }

    #[Test]
    public function a_request_refused_by_one_limit_is_taken_back_from_the_others(): void
    {
        $listener = self::listener();
        $listener->handle(self::context(reRun: false, payload: new RateLimitListenerTwoLimitsPayload()));

        // Hammering through the one-minute block must not burn the hour.
        for ($i = 0; $i < 20; $i++) {
            try {
                $listener->handle(self::context(reRun: false, payload: new RateLimitListenerTwoLimitsPayload()));
            } catch (RateLimitException) {
            }
        }

        $storage = (new \ReflectionProperty($listener, 'limiter'))->getValue($listener);
        $hour = $storage->attempt(RateLimitListenerTwoLimitsPayload::class, 1000, 3600, \Semitexa\Core\RateLimit\RateLimitKey::Ip);
        self::assertSame(998, $hour->remaining, 'the hour holds the one allowed request and this probe, not the refused twenty');
    }

    #[Test]
    public function a_payload_without_a_limit_is_never_counted(): void
    {
        $listener = self::listener();
        for ($i = 0; $i < 5; $i++) {
            $listener->handle(self::context(reRun: false, payload: new \stdClass()));
        }

        $this->addToAssertionCount(1);
    }

    private static function listener(): RateLimitListener
    {
        $storage = new RateLimitStorage();
        $storage->use(new InMemoryRateLimitStore());
        $limiter = new RateLimiter();
        (new \ReflectionProperty($limiter, 'storage'))->setValue($limiter, $storage);
        (new \ReflectionProperty($limiter, 'request'))->setValue($limiter, self::request());

        $listener = new RateLimitListener();
        (new \ReflectionProperty($listener, 'limiter'))->setValue($listener, $limiter);

        return $listener;
    }

    private static function context(bool $reRun, ?object $payload = null): RequestPipelineContext
    {
        $context = new RequestPipelineContext(
            requestDto: $payload ?? new RateLimitListenerTestPayload(),
            route: new DiscoveredRoute(
                path: '/limited',
                methods: ['GET'],
                name: 'limited',
                requestClass: RateLimitListenerTestPayload::class,
                responseClass: null,
                handlers: [],
                type: 'http_request',
                transport: null,
                produces: null,
                consumes: null,
                module: 'stub',
            ),
            request: self::request(),
        );
        $context->reRun = $reRun;

        return $context;
    }

    private static function request(): Request
    {
        return new Request('GET', '/limited', [], [], [], ['remote_addr' => '203.0.113.20'], []);
    }
}
