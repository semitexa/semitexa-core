<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Exception\InvalidResolvedRelationException;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResolvedResourceGraph;
use Semitexa\Core\Resource\ResourceExpansionPipeline;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6e: to-many resolver overlay end-to-end.
 *
 * `addresses` is now resolver-backed. Together with Phase 6d's
 * resolver-backed `profile`, both the to-one and to-many overlay
 * paths run through the same pipeline and renderer chain.
 */
final class Phase6eListResolverTest extends TestCase
{
    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerWithBothResolvedResource::class));
        return $registry;
    }

    private function root(): CustomerWithBothResolvedResource
    {
        return new CustomerWithBothResolvedResource(
            id: '7',
            profile: ResourceRef::to(
                ResourceIdentity::of('profile', '7-profile'),
                '/x/7/profile',
            ),
            addresses: ResourceRefList::to('/x/7/addresses'),
        );
    }

    /** @param array<class-string, object> $bindings */
    private function container(array $bindings): ContainerInterface
    {
        return new class ($bindings) implements ContainerInterface {
            /** @param array<class-string, object> $b */
            public function __construct(private array $b) {}

            public function get(string $id): object
            {
                if (!array_key_exists($id, $this->b)) {
                    throw new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
                }
                return $this->b[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->b);
            }
        };
    }

    private function context(IncludeSet $includes): RenderContext
    {
        return new RenderContext(profile: RenderProfile::Json, includes: $includes);
    }

    #[Test]
    public function pipeline_invokes_addresses_resolver_only_when_token_requested(): void
    {
        RecordingAddressesResolver::reset();
        RecordingProfileResolver::reset();

        $pipeline = ResourceExpansionPipeline::forTesting(
            $this->registry(),
            $this->container([
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
            ]),
        );

        // No include → neither resolver runs.
        $pipeline->expand($this->root(), IncludeSet::empty(), $this->context(IncludeSet::empty()));
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingProfileResolver::$calls);

        // Profile-only → addresses resolver does NOT run.
        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertCount(1, RecordingProfileResolver::$calls);

        // Addresses-only → profile resolver does NOT run again.
        RecordingProfileResolver::reset();
        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );
        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingProfileResolver::$calls);

        // Both → each resolver runs exactly once.
        RecordingAddressesResolver::reset();
        RecordingProfileResolver::reset();
        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile,addresses'),
            $this->context(IncludeSet::fromQueryString('profile,addresses')),
        );
        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertCount(1, RecordingProfileResolver::$calls);
    }

    #[Test]
    public function pipeline_stores_list_value_in_resolved_graph(): void
    {
        $pipeline = ResourceExpansionPipeline::forTesting(
            $this->registry(),
            $this->container([
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );

        $graph = $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );

        $rootIdentity = ResourceIdentity::of('phase6e_customer', '7');
        self::assertTrue($graph->has($rootIdentity, 'addresses'));

        $list = $graph->lookup($rootIdentity, 'addresses');
        self::assertIsArray($list);
        self::assertCount(2, $list);
        self::assertInstanceOf(AddressResource::class, $list[0]);
        self::assertSame('7-a1', $list[0]->id);
    }

    #[Test]
    public function json_renderer_consumes_addresses_list_overlay(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root  = $this->root();
        $ctx   = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('addresses'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        self::assertArrayHasKey('addresses', $output);
        self::assertSame('/x/7/addresses', $output['addresses']['href']);
        self::assertCount(2, $output['addresses']['data']);
        self::assertSame(2, $output['addresses']['total']);
        self::assertSame('Kyiv', $output['addresses']['data'][0]['city']);
    }

    #[Test]
    public function jsonld_renderer_consumes_addresses_list_overlay(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );
        $renderer = JsonLdResourceRenderer::forTesting($registry);

        $root  = $this->root();
        $ctx   = new RenderContext(
            profile:  RenderProfile::JsonLd,
            includes: IncludeSet::fromQueryString('addresses'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        self::assertIsArray($output['addresses']);
        self::assertCount(2, $output['addresses']);
        self::assertSame('Kyiv', $output['addresses'][0]['city']);
    }

    #[Test]
    public function graphql_renderer_consumes_addresses_list_overlay(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );
        $renderer = GraphqlResourceRenderer::forTesting($registry);

        $root  = $this->root();
        $ctx   = new RenderContext(
            profile:  RenderProfile::GraphQL,
            includes: IncludeSet::fromQueryString('addresses'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        $rootField = array_key_first($output['data']);
        self::assertIsArray($output['data'][$rootField]['addresses']);
        self::assertCount(2, $output['data'][$rootField]['addresses']);
        self::assertSame('Kyiv', $output['data'][$rootField]['addresses'][0]['city']);
    }

    #[Test]
    public function empty_resolved_list_renders_data_array_with_zero_total_in_json(): void
    {
        $registry = $this->registry();
        $emptyResolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    $out[$p->urn()] = [];
                }
                return $out;
            }
        };
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => $emptyResolver,
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root  = $this->root();
        $ctx   = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('addresses'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        self::assertSame([], $output['addresses']['data']);
        self::assertSame(0, $output['addresses']['total']);
    }

    #[Test]
    public function null_for_to_many_fails_loud(): void
    {
        $registry = $this->registry();
        $badResolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    $out[$p->urn()] = null; // illegal for to-many.
                }
                return $out;
            }
        };
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => $badResolver,
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/cannot be null/');

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );
    }

    #[Test]
    public function non_resource_list_item_fails_loud(): void
    {
        $registry = $this->registry();
        $badResolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    $out[$p->urn()] = ['not-a-resource'];
                }
                return $out;
            }
        };
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => $badResolver,
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/not ResourceObjectInterface/');

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );
    }

    #[Test]
    public function non_list_value_for_to_many_fails_loud(): void
    {
        $registry = $this->registry();
        $badResolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    // Associative array, not a list.
                    $out[$p->urn()] = ['k' => new AddressResource('a', 'b', 'c')];
                }
                return $out;
            }
        };
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => $badResolver,
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/must be a list/');

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );
    }

    #[Test]
    public function missing_parent_key_for_to_many_yields_empty_list_overlay(): void
    {
        $registry = $this->registry();
        $emptyMap = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                return []; // omit every parent key.
            }
        };
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => $emptyMap,
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );

        $rootIdentity = ResourceIdentity::of('phase6e_customer', '7');
        $graph = $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );

        // Phase 6d documented behaviour: missing parent key → "absent" =
        // empty list for to-many. The overlay records [].
        self::assertTrue($graph->has($rootIdentity, 'addresses'));
        self::assertSame([], $graph->lookup($rootIdentity, 'addresses'));
    }

    #[Test]
    public function no_include_renders_reference_only_addresses(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root  = $this->root();
        $ctx   = $this->context(IncludeSet::empty());
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        self::assertSame('/x/7/addresses', $output['addresses']['href']);
        self::assertArrayNotHasKey('data', $output['addresses']);
    }

    #[Test]
    public function root_dto_is_not_mutated_by_list_resolution(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
            ]),
        );

        $root             = $this->root();
        $rootAddressesRef = $root->addresses;

        $pipeline->expand(
            $root,
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );

        // Root DTO still carries the bare reference-only ResourceRefList.
        self::assertSame($rootAddressesRef, $root->addresses);
        self::assertNull($root->addresses->data);
    }
}
