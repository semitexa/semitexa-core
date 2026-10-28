<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Application;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\RateLimit\InMemoryRateLimitStore;
use Semitexa\Core\RateLimit\RateLimitStorage;
use Semitexa\Core\Request;
use Semitexa\Core\Tests\Fixtures\RateLimitDemo\Payload\LimitedPingPayload;
use Semitexa\Core\Tests\Support\FixtureModules;

/**
 * #[RateLimit(2, 60)] through the real pipeline (Application::handleRequest):
 * the third request from one address is a 429 that says when to come back,
 * and another address is not affected (tk-parity-rate-limiting, rl-2).
 */
final class RateLimitRuntimeTest extends TestCase
{
    private Application $app;

    public static function setUpBeforeClass(): void
    {
        FixtureModules::install();
    }

    public static function tearDownAfterClass(): void
    {
        FixtureModules::uninstall();
    }

    protected function setUp(): void
    {
        $this->app = new Application();
        $container = ContainerFactory::get();
        $container->get(AttributeDiscovery::class)->initialize();
        // A fresh store: counters from another test must not leak in.
        $container->get(RateLimitStorage::class)->use(new InMemoryRateLimitStore());
    }

    #[Test]
    public function the_request_over_the_limit_is_a_429_with_retry_after(): void
    {
        self::assertSame(200, $this->get('203.0.113.10')->getStatusCode());
        self::assertSame(200, $this->get('203.0.113.10')->getStatusCode());

        $refused = $this->get('203.0.113.10');

        self::assertSame(429, $refused->getStatusCode());
        $headers = array_change_key_case($refused->getHeaders(), CASE_LOWER);
        self::assertGreaterThan(0, (int) ($headers['retry-after'] ?? 0));
        self::assertSame('2', (string) ($headers['ratelimit-limit'] ?? ''));
        self::assertSame('0', (string) ($headers['ratelimit-remaining'] ?? ''));

        self::assertSame(200, $this->get('203.0.113.11')->getStatusCode(), 'another address has its own count');
    }

    private function get(string $address): HttpResponse
    {
        $request = new Request(
            method: 'GET',
            uri: LimitedPingPayload::PATH,
            headers: ['Accept' => 'application/json'],
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => LimitedPingPayload::PATH, 'remote_addr' => $address],
            cookies: [],
        );

        try {
            return $this->app->handleRequest($request);
        } finally {
            $this->app->requestScopedContainer->reset();
            AuthContextStore::clear();
        }
    }
}
