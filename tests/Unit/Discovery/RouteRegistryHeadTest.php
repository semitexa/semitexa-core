<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\RouteRegistry;

/**
 * A server that answers GET must answer HEAD (RFC 9110 §9.3.1). Before this,
 * `curl -I` got a 404 on every page of a fresh install.
 */
final class RouteRegistryHeadTest extends TestCase
{
    #[Test]
    public function head_resolves_to_the_get_route_on_an_exact_path(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/', 'methods' => ['GET'], 'name' => 'home']);

        self::assertSame('home', $registry->find('/', 'HEAD')['name'] ?? null);
    }

    #[Test]
    public function head_resolves_to_the_get_route_on_a_pattern_path(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/items/{id}', 'methods' => ['GET'], 'name' => 'item']);

        self::assertSame('item', $registry->find('/items/7', 'HEAD')['name'] ?? null);
    }

    #[Test]
    public function head_does_not_reach_a_route_without_get(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/save', 'methods' => ['POST'], 'name' => 'save']);

        self::assertNull($registry->find('/save', 'HEAD'));
    }

    #[Test]
    public function the_route_keeps_reporting_only_its_declared_methods(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/', 'methods' => ['GET'], 'name' => 'home']);

        self::assertSame(['GET'], $registry->find('/', 'HEAD')['methods'] ?? null);
    }

    #[Test]
    public function head_does_not_reach_an_sse_or_stream_route(): void
    {
        // Their handlers write the raw Swoole response past the emitter, so a
        // HEAD would open a live stream and get a body.
        $registry = new RouteRegistry();
        $registry->register(['path' => '/feed', 'methods' => ['GET'], 'name' => 'feed', 'transport' => 'sse']);
        $registry->register(['path' => '/dl/{id}', 'methods' => ['GET'], 'name' => 'dl', 'transport' => 'stream']);

        self::assertNull($registry->find('/feed', 'HEAD'));
        self::assertNull($registry->find('/dl/7', 'HEAD'));
        self::assertSame('feed', $registry->find('/feed', 'GET')['name'] ?? null);
    }

    #[Test]
    public function an_explicit_head_route_wins_over_a_get_route_registered_before_it(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/', 'methods' => ['GET'], 'name' => 'home']);
        $registry->register(['path' => '/', 'methods' => ['HEAD'], 'name' => 'home-head']);
        $registry->register(['path' => '/items/{id}', 'methods' => ['GET'], 'name' => 'item']);
        $registry->register(['path' => '/items/{id}', 'methods' => ['HEAD'], 'name' => 'item-head']);

        self::assertSame('home-head', $registry->find('/', 'HEAD')['name'] ?? null);
        self::assertSame('item-head', $registry->find('/items/7', 'HEAD')['name'] ?? null);
        self::assertSame('home', $registry->find('/', 'GET')['name'] ?? null);
        self::assertSame('item', $registry->find('/items/7', 'GET')['name'] ?? null);
    }
}
