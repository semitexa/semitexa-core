<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\RouteRegistry;

/**
 * A path that exists for other methods is a 405 with Allow (RFC 9110
 * §15.5.6), not a 404: allowedMethods() is what tells the two apart.
 */
final class RouteRegistryAllowedMethodsTest extends TestCase
{
    #[Test]
    public function an_exact_path_lists_its_methods_including_the_synthesized_head(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/', 'methods' => ['GET'], 'name' => 'home']);

        self::assertNull($registry->find('/', 'POST'));
        self::assertSame(['GET', 'HEAD'], $registry->allowedMethods('/'));
    }

    #[Test]
    public function a_pattern_path_unions_the_methods_of_every_matching_route(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/items/{id}', 'methods' => ['GET'], 'name' => 'item']);
        $registry->register(['path' => '/items/{id}', 'methods' => ['DELETE'], 'name' => 'item.delete']);

        self::assertSame(['DELETE', 'GET', 'HEAD'], $registry->allowedMethods('/items/7'));
    }

    #[Test]
    public function an_unknown_path_has_no_methods_and_stays_a_404(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/', 'methods' => ['GET'], 'name' => 'home']);

        self::assertSame([], $registry->allowedMethods('/nowhere'));
    }
}
