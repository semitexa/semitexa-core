<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Resource\Exception\InvalidResourceResolverException;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\ResourceExpansionPipeline;

/**
 * A misconfigured container binding for a `#[ResolveWith]` resolver class can
 * hand back anything, not just the wrong object. Before this fix, the guard
 * that builds `InvalidResourceResolverException::$actualClass` read
 * `$resolver::class` unconditionally — a fatal error on a non-object value
 * (e.g. a factory binding that resolves to an array) instead of the
 * diagnosable 500 the guard exists to produce.
 */
final class ResourceExpansionPipelineResolverGuardTest extends TestCase
{
    #[Test]
    public function a_non_object_resolver_binding_raises_invalid_resource_resolver_exception(): void
    {
        $container = new ResolverGuardArrayContainer([
            'App\\Resolver\\Misconfigured' => ['not' => 'a resolver'],
        ]);

        $pipeline = ResourceExpansionPipeline::forTesting(
            ResourceMetadataRegistry::forTesting(new ResourceMetadataExtractor()),
            $container,
        );

        $field = new ResourceFieldMetadata(
            name: 'profile',
            kind: ResourceFieldKind::RefOne,
            nullable: true,
            resolverClass: 'App\\Resolver\\Misconfigured',
        );

        $method = new \ReflectionMethod($pipeline, 'makeResolver');
        $method->setAccessible(true);

        try {
            $method->invoke($pipeline, $field);
            self::fail('expected InvalidResourceResolverException');
        } catch (InvalidResourceResolverException $e) {
            self::assertSame('array', $e->actualClass);
            self::assertStringContainsString('array', $e->getMessage());
        }
    }
}

final class ResolverGuardArrayContainer implements ContainerInterface
{
    /** @param array<string, mixed> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->services)) {
            throw new class("no binding for {$id}") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
