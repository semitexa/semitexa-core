<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Exception\InvalidResolvedRelationException;
use Semitexa\Core\Resource\Exception\InvalidResourceResolverException;
use Semitexa\Core\Resource\Exception\ResourceResolverNotFoundException;
use Semitexa\Core\Resource\IncludeSet;
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
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithResolvedProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6d: ResourceExpansionPipeline runtime behaviour.
 */
final class Phase6dExpansionPipelineTest extends TestCase
{
    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));
        $registry->register($extractor->extract(CustomerWithResolvedProfileResource::class));
        return $registry;
    }

    private function customerWithProfileLink(string $id = '42'): CustomerWithResolvedProfileResource
    {
        return new CustomerWithResolvedProfileResource(
            id: $id,
            profile: ResourceRef::to(
                ResourceIdentity::of('profile', $id . '-profile'),
                '/x/' . $id . '/profile',
            ),
            addresses: ResourceRefList::to('/x/' . $id . '/addresses'),
        );
    }

    private function context(IncludeSet $includes): RenderContext
    {
        return new RenderContext(
            profile:  RenderProfile::Json,
            includes: $includes,
        );
    }

    private function containerWith(string $resolverClass, object $instance): ContainerInterface
    {
        return new class ($resolverClass, $instance) implements ContainerInterface {
            public function __construct(private string $key, private object $value) {}

            public function get(string $id): object
            {
                if ($id === $this->key) {
                    return $this->value;
                }
                throw new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return $id === $this->key;
            }
        };
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function empty_include_set_returns_root_only_overlay(): void
    {
        RecordingProfileResolver::reset();
        $registry = $this->registry();
        $container = $this->containerWith(RecordingProfileResolver::class, new RecordingProfileResolver());
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root = $this->customerWithProfileLink();
        $graph = $pipeline->expand($root, IncludeSet::empty(), $this->context(IncludeSet::empty()));

        self::assertSame($root, $graph->root);
        self::assertTrue($graph->isEmpty());
        self::assertSame([], RecordingProfileResolver::$calls);
    }

    #[Test]
    public function pipeline_ignores_handler_provided_includes(): void
    {
        RecordingProfileResolver::reset();
        $registry  = $this->registry();
        $container = $this->emptyContainer();
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        // `addresses` is expandable but has no #[ResolveWith] on the
        // fixture — so the pipeline must skip it and not attempt
        // container resolution. The empty container would otherwise
        // throw.
        $root  = $this->customerWithProfileLink();
        $graph = $pipeline->expand(
            $root,
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );

        self::assertTrue($graph->isEmpty());
        self::assertSame([], RecordingProfileResolver::$calls);
    }

    #[Test]
    public function pipeline_invokes_resolver_for_resolver_backed_token(): void
    {
        RecordingProfileResolver::reset();
        $registry  = $this->registry();
        $resolver  = new RecordingProfileResolver();
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root  = $this->customerWithProfileLink('42');
        $ctx   = $this->context(IncludeSet::fromQueryString('profile'));
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);

        self::assertCount(1, RecordingProfileResolver::$calls);
        $call = RecordingProfileResolver::$calls[0];

        self::assertCount(1, $call['parents']);
        self::assertInstanceOf(ResourceIdentity::class, $call['parents'][0]);
        self::assertSame('phase6d_customer', $call['parents'][0]->type);
        self::assertSame('42', $call['parents'][0]->id);
        self::assertSame($ctx, $call['ctx']);

        $rootIdentity = ResourceIdentity::of('phase6d_customer', '42');
        self::assertTrue($graph->has($rootIdentity, 'profile'));

        $resolved = $graph->lookup($rootIdentity, 'profile');
        self::assertInstanceOf(ProfileResource::class, $resolved);
        self::assertSame('42-profile', $resolved->id);
    }

    #[Test]
    public function pipeline_does_not_invoke_resolver_when_token_not_requested(): void
    {
        RecordingProfileResolver::reset();
        $registry  = $this->registry();
        $resolver  = new RecordingProfileResolver();
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root = $this->customerWithProfileLink();
        $pipeline->expand($root, IncludeSet::empty(), $this->context(IncludeSet::empty()));
        $pipeline->expand(
            $root,
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );

        self::assertSame([], RecordingProfileResolver::$calls);
    }

    #[Test]
    public function container_failure_becomes_resource_resolver_not_found(): void
    {
        $registry  = $this->registry();
        $container = $this->emptyContainer();
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root = $this->customerWithProfileLink();

        try {
            $pipeline->expand(
                $root,
                IncludeSet::fromQueryString('profile'),
                $this->context(IncludeSet::fromQueryString('profile')),
            );
            self::fail('Expected ResourceResolverNotFoundException.');
        } catch (ResourceResolverNotFoundException $e) {
            self::assertSame(500, $e->getStatusCode()->value);
            self::assertSame(RecordingProfileResolver::class, $e->resolverClass);
            self::assertSame('profile', $e->relationName);
        }
    }

    #[Test]
    public function container_returning_wrong_type_becomes_invalid_resolver(): void
    {
        $registry  = $this->registry();
        $container = $this->containerWith(RecordingProfileResolver::class, new \stdClass());
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root = $this->customerWithProfileLink();

        try {
            $pipeline->expand(
                $root,
                IncludeSet::fromQueryString('profile'),
                $this->context(IncludeSet::fromQueryString('profile')),
            );
            self::fail('Expected InvalidResourceResolverException.');
        } catch (InvalidResourceResolverException $e) {
            self::assertSame(500, $e->getStatusCode()->value);
            self::assertSame(RecordingProfileResolver::class, $e->resolverClass);
            self::assertSame('stdClass', $e->actualClass);
        }
    }

    #[Test]
    public function resolver_returning_wrong_target_type_fails_loud(): void
    {
        $registry = $this->registry();
        // A resolver that returns an AddressResource for a profile slot —
        // metadata says target=ProfileResource, so this must fail.
        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $parent) {
                    $out[$parent->urn()] = new AddressResource('a1', 'Kyiv', 'Khreshchatyk 1');
                }
                return $out;
            }
        };
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/expected target type .*ProfileResource/');

        $pipeline->expand(
            $this->customerWithProfileLink(),
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
    }

    #[Test]
    public function resolver_returning_non_string_key_fails_loud(): void
    {
        $registry = $this->registry();
        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                return [42 => new ProfileResource('x', 'y')];
            }
        };
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/key must be a string urn/');

        $pipeline->expand(
            $this->customerWithProfileLink(),
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
    }

    #[Test]
    public function missing_parent_key_is_treated_as_absent(): void
    {
        $registry = $this->registry();
        // Resolver returns an empty map — the pipeline interprets the
        // missing key as "no related entity" (null for to-one).
        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                return [];
            }
        };
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $rootIdentity = ResourceIdentity::of('phase6d_customer', '42');
        $graph        = $pipeline->expand(
            $this->customerWithProfileLink('42'),
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );

        self::assertTrue($graph->has($rootIdentity, 'profile'));
        self::assertNull($graph->lookup($rootIdentity, 'profile'));
    }

    #[Test]
    public function repeated_expansion_with_same_inputs_is_deterministic(): void
    {
        RecordingProfileResolver::reset();
        $registry  = $this->registry();
        $resolver  = new RecordingProfileResolver();
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root = $this->customerWithProfileLink();
        $ctx  = $this->context(IncludeSet::fromQueryString('profile'));

        $g1 = $pipeline->expand($root, $ctx->includes, $ctx);
        $g2 = $pipeline->expand($root, $ctx->includes, $ctx);

        $rootIdentity = ResourceIdentity::of('phase6d_customer', '42');
        self::assertEquals($g1->lookup($rootIdentity, 'profile'), $g2->lookup($rootIdentity, 'profile'));
        self::assertCount(2, RecordingProfileResolver::$calls, 'Each expand() runs the resolver once.');
    }

    #[Test]
    public function different_includes_do_not_leak_resolved_data(): void
    {
        RecordingProfileResolver::reset();
        $registry  = $this->registry();
        $resolver  = new RecordingProfileResolver();
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root = $this->customerWithProfileLink();

        $with = $pipeline->expand(
            $root,
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
        $without = $pipeline->expand(
            $root,
            IncludeSet::empty(),
            $this->context(IncludeSet::empty()),
        );

        $rootIdentity = ResourceIdentity::of('phase6d_customer', '42');
        self::assertTrue($with->has($rootIdentity, 'profile'));
        self::assertFalse($without->has($rootIdentity, 'profile'));
    }

    #[Test]
    public function root_dto_is_not_mutated(): void
    {
        $registry  = $this->registry();
        $resolver  = new RecordingProfileResolver();
        $container = $this->containerWith(RecordingProfileResolver::class, $resolver);
        $pipeline  = ResourceExpansionPipeline::forTesting($registry, $container);

        $root        = $this->customerWithProfileLink();
        $rootProfile = $root->profile;

        $pipeline->expand(
            $root,
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );

        // The root DTO's `profile` is still the reference-only ResourceRef
        // produced by the handler; the resolver output landed only on the
        // ResolvedResourceGraph overlay.
        self::assertSame($rootProfile, $root->profile);
        self::assertNull($root->profile?->data);
    }
}
