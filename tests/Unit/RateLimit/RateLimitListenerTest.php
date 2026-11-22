<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\RateLimit;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Http\PayloadFactory;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\RateLimit\InMemoryRateLimitStore;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\RateLimit\RateLimitListener;
use Semitexa\Core\RateLimit\RateLimitStorage;
use Semitexa\Core\RateLimit\RateLimitStoreInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Tests\Unit\RateLimit\Fixture\LimitedPayloadChildRepeatingLimit;
use Semitexa\Core\Tests\Unit\RateLimit\Fixture\LimitedPayloadChildWithOwnLimit;
use Semitexa\Core\Tests\Unit\RateLimit\Fixture\LimitedPayloadPart;
use Semitexa\Core\Tests\Unit\RateLimit\Fixture\LimitedPayloadWithParts;

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
    /** @var array<string, class-string> PayloadFactory's wrapper cache as it was before the test. */
    private array $wrapperCache = [];

    protected function setUp(): void
    {
        /** @var array<string, class-string> $cache */
        $cache = (new \ReflectionProperty(PayloadFactory::class, 'classCache'))->getValue();
        $this->wrapperCache = $cache;
    }

    protected function tearDown(): void
    {
        // withPart() leaves a wrapper in the factory's static cache; a failed assertion must not keep it there.
        (new \ReflectionProperty(PayloadFactory::class, 'classCache'))->setValue(null, $this->wrapperCache);
    }

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
        $store = new class implements RateLimitStoreInterface {
            public int $hits = 0;

            public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array
            {
                $this->hits++;

                return [1, 0];
            }

            public function undo(string $currentKey): void {}
        };
        $listener = self::listener($store);
        for ($i = 0; $i < 5; $i++) {
            $listener->handle(self::context(reRun: false, payload: new \stdClass()));
        }

        self::assertSame(0, $store->hits, 'no counter was touched');
    }

    #[Test]
    public function a_payload_built_with_a_part_is_limited_like_the_plain_one(): void
    {
        self::assertSame(4, self::refusedOfFive(static fn(): object => new LimitedPayloadWithParts()), 'the plain payload, as before');
        self::assertSame(4, self::refusedOfFive(self::withPart(...)), 'the runtime subclass carries no attribute of its own');
    }

    #[Test]
    public function a_payload_built_with_a_part_shares_the_plain_payloads_bucket(): void
    {
        self::assertTrue(is_subclass_of(self::withPart(), LimitedPayloadWithParts::class), 'the factory built a subclass');

        $listener = self::listener();
        $listener->handle(self::context(reRun: false, payload: self::withPart()));

        // One count for the route, whichever class the request arrived as.
        $limiter = self::limiterOf($listener);
        $probe = $limiter->attempt(LimitedPayloadWithParts::class, 1, 60, \Semitexa\Core\RateLimit\RateLimitKey::Ip);
        self::assertFalse($probe->allowed, 'the declaring class\'s bucket already holds the request');

        $this->expectException(RateLimitException::class);
        $listener->handle(self::context(reRun: false, payload: new LimitedPayloadWithParts()));
    }

    #[Test]
    public function a_subclass_with_its_own_limit_is_still_held_by_its_parents(): void
    {
        $listener = self::listener();
        $listener->handle(self::context(reRun: false, payload: new LimitedPayloadChildWithOwnLimit()));

        // Its own limit allows five; the parent's one a minute still refuses the second.
        try {
            $listener->handle(self::context(reRun: false, payload: new LimitedPayloadChildWithOwnLimit()));
            self::fail('the parent\'s limit was dropped');
        } catch (RateLimitException $refused) {
            self::assertSame('1', $refused->headers()['RateLimit-Limit'] ?? null);
        }

        // The refused request was taken back from the subclass's own bucket.
        $limiter = self::limiterOf($listener);
        $own = $limiter->attempt(LimitedPayloadChildWithOwnLimit::class, 5, 60, \Semitexa\Core\RateLimit\RateLimitKey::Ip);
        self::assertSame(3, $own->remaining, 'the one allowed request and this probe');
    }

    #[Test]
    public function the_same_limit_on_a_parent_and_its_child_is_counted_once(): void
    {
        $listener = self::listener();

        // Counted twice, a limit of 1 refused even the first request, and took both hits back: every request refused.
        $listener->handle(self::context(reRun: false, payload: new LimitedPayloadChildRepeatingLimit()));

        self::assertSame(4, self::refusedOfFive(static fn(): object => new LimitedPayloadChildRepeatingLimit()), 'one passes, then the limit holds');

        $this->expectException(RateLimitException::class);
        $listener->handle(self::context(reRun: false, payload: new LimitedPayloadChildRepeatingLimit()));
    }

    private static function withPart(): object
    {
        // The factory refuses a class or trait that is not loaded yet.
        trait_exists(LimitedPayloadPart::class);

        return PayloadFactory::createInstance((new LimitedPayloadWithParts())::class, [LimitedPayloadPart::class]);
    }

    private static function limiterOf(RateLimitListener $listener): RateLimiter
    {
        $limiter = (new \ReflectionProperty($listener, 'limiter'))->getValue($listener);
        self::assertInstanceOf(RateLimiter::class, $limiter);

        return $limiter;
    }

    /** @param \Closure(): object $payload */
    private static function refusedOfFive(\Closure $payload): int
    {
        $listener = self::listener();
        $refused = 0;
        for ($i = 0; $i < 5; $i++) {
            try {
                $listener->handle(self::context(reRun: false, payload: $payload()));
            } catch (RateLimitException) {
                $refused++;
            }
        }

        return $refused;
    }

    private static function listener(?RateLimitStoreInterface $store = null): RateLimitListener
    {
        $storage = new RateLimitStorage();
        $storage->use($store ?? new InMemoryRateLimitStore());
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
