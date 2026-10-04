<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\RouteExposure;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\PayloadAttributeSchema;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\ConfigurationException;

/**
 * A route with `exposure: Hug` has no door of its own: it is never matched by
 * a path, only found by name — which is how HUG admits a feed subscription.
 */
final class RouteExposureTest extends TestCase
{
    #[Test]
    public function a_hug_route_is_found_by_name_and_never_by_a_path(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '', 'methods' => ['GET'], 'name' => 'platform-ui.form-doc', 'exposure' => 'hug']);

        self::assertSame('platform-ui.form-doc', $registry->findByName('platform-ui.form-doc')['name'] ?? null);
        self::assertNull($registry->find('/', 'GET'));
        self::assertNull($registry->find('', 'GET'));
        self::assertSame([], $registry->allowedMethods('/'));
    }

    #[Test]
    public function a_public_route_is_unchanged(): void
    {
        $registry = new RouteRegistry();
        $registry->register(['path' => '/feed', 'methods' => ['GET'], 'name' => 'feed', 'exposure' => 'public']);

        self::assertSame('feed', $registry->find('/feed', 'GET')['name'] ?? null);
    }

    #[Test]
    public function a_hug_route_must_not_declare_a_path(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/must not define a path/');
        (new PayloadAttributeSchema())->applyDefaults($this->attr(['path' => '/__ui/form-doc', 'name' => 'x']), 'X', 'App\\X');
    }

    #[Test]
    public function a_hug_route_must_be_named(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/explicit name/');
        (new PayloadAttributeSchema())->applyDefaults($this->attr(['path' => null, 'name' => null]), 'X', 'App\\X');
    }

    #[Test]
    public function a_named_hug_route_resolves_with_an_empty_path(): void
    {
        $resolved = (new PayloadAttributeSchema())->applyDefaults($this->attr(['path' => null, 'name' => 'platform-ui.form-doc']), 'X', 'App\\X');

        self::assertSame('', $resolved['path']);
        self::assertSame(RouteExposure::Hug, $resolved['exposure']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function attr(array $overrides): array
    {
        return array_replace([
            'path' => null, 'methods' => ['GET'], 'name' => null, 'requirements' => null, 'defaults' => null,
            'options' => null, 'tags' => null, 'accessType' => PayloadAccessType::Public, 'responseWith' => null,
            'consumes' => null, 'produces' => null, 'transport' => null, 'sseGateModel' => null,
            'exposure' => RouteExposure::Hug, 'renderProfile' => null, 'responsesByProfile' => null,
        ], $overrides);
    }
}
