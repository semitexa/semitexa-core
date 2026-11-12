<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\BootException;
use Semitexa\Core\Discovery\BootSeverity;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Tests\Unit\Discovery\Fixture\RefusedRoutePayload;

/**
 * A route attribute that refuses its arguments is a mistake to report, not a
 * class to skip (tk-route-attribute-refusal-is-a-skip). On 2026-11-10
 * #[AsCollectionFeed(access: Public)] with no reason booted with no route: a
 * 404, and only "[skip][AttributeDiscovery]: 1" in the boot summary.
 */
final class RouteAttributeRefusalTest extends TestCase
{
    #[Test]
    public function a_refused_route_attribute_is_an_invalid_usage_that_strict_mode_stops_on(): void
    {
        $diagnostics = new BootDiagnostics();
        $meta = $this->collect($diagnostics);

        self::assertArrayNotHasKey(RefusedRoutePayload::class, $meta, 'the route is still left out');
        $warnings = $diagnostics->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame(BootSeverity::InvalidUsage, $warnings[0]->severity);
        self::assertStringContainsString(RefusedRoutePayload::class, $warnings[0]->message);
        self::assertStringContainsString('a public route needs a reason', $warnings[0]->message);

        $this->expectException(BootException::class);
        $diagnostics->setVerbosity(BootDiagnostics::VERBOSITY_SILENT);
        $diagnostics->finalize(strict: true);
    }

    /** @return array<string, mixed> */
    private function collect(BootDiagnostics $diagnostics): array
    {
        $classes = $this->createStub(ClassDiscovery::class);
        $classes->method('findClassesWithAttributeInstanceof')->willReturn([RefusedRoutePayload::class]);
        $modules = $this->createStub(ModuleRegistry::class);
        $modules->method('isClassActive')->willReturn(true);
        $discovery = new AttributeDiscovery($classes, $modules, $this->createStub(RouteRegistry::class));

        return (new \ReflectionMethod($discovery, 'collectPayloadMetadata'))->invoke($discovery, $diagnostics);
    }
}
