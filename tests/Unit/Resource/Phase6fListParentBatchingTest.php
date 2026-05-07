<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Exception\InvalidResolvedRelationException;
use Semitexa\Core\Resource\IncludeSet;
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
 * Phase 6f: list-parent batching in `ResourceExpansionPipeline`.
 *
 * The pipeline groups parents that share a resolver-backed relation
 * into a bucket and dispatches exactly one `resolveBatch()` per
 * bucket, regardless of parent count. Single-parent expansion routes
 * through the same machinery as a one-entry bucket and stays
 * byte-identical to Phase 6e.
 */
final class Phase6fListParentBatchingTest extends TestCase
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

    private function customer(string $id): CustomerWithBothResolvedResource
    {
        return new CustomerWithBothResolvedResource(
            id: $id,
            profile: ResourceRef::to(
                ResourceIdentity::of('profile', $id . '-profile'),
                "/x/{$id}/profile",
            ),
            addresses: ResourceRefList::to("/x/{$id}/addresses"),
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

    private function pipeline(ResourceMetadataRegistry $registry): ResourceExpansionPipeline
    {
        return ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
            ]),
        );
    }

    #[Test]
    public function expand_many_with_empty_list_returns_empty_collection_graph(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $pipeline = $this->pipeline($this->registry());
        $includes = IncludeSet::fromQueryString('profile,addresses');

        $graph = $pipeline->expandMany([], $includes, $this->context($includes));

        self::assertSame([], $graph->roots);
        self::assertNull($graph->root);
        self::assertTrue($graph->isEmpty());
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function expand_many_with_single_parent_matches_single_parent_expand(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);
        $includes = IncludeSet::fromQueryString('profile,addresses');
        $ctx      = $this->context($includes);

        $root = $this->customer('7');

        $single = $pipeline->expand($root, $includes, $ctx);

        // Reset before the multi-parent call so we can compare fresh
        // call counters.
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $many = $pipeline->expandMany([$root], $includes, $ctx);

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingAddressesResolver::$calls);

        // assertEquals (not assertSame): each pipeline call constructs
        // a fresh DTO, so the maps are equal by value but not by
        // identity. The bucket structure is identical.
        self::assertEquals($single->resolved, $many->resolved);
        self::assertSame(array_keys($single->resolved), array_keys($many->resolved));
        self::assertSame([$root], $many->roots);
        self::assertSame($root, $many->root);
    }

    #[Test]
    public function multi_parent_profile_request_invokes_resolver_once_with_all_identities(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $roots    = [$this->customer('1'), $this->customer('2'), $this->customer('3')];
        $includes = IncludeSet::fromQueryString('profile');

        $graph = $pipeline->expandMany($roots, $includes, $this->context($includes));

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);

        $call = RecordingProfileResolver::$calls[0];
        self::assertCount(3, $call['parents']);
        self::assertSame(
            ['urn:semitexa:phase6e_customer:1', 'urn:semitexa:phase6e_customer:2', 'urn:semitexa:phase6e_customer:3'],
            array_map(fn (ResourceIdentity $i) => $i->urn(), $call['parents']),
        );

        // Per-parent overlay slots populated correctly.
        foreach ($roots as $root) {
            $identity = ResourceIdentity::of('phase6e_customer', $root->id);
            self::assertTrue($graph->has($identity, 'profile'));
            $value = $graph->lookup($identity, 'profile');
            self::assertInstanceOf(ProfileResource::class, $value);
            self::assertSame($root->id . '-profile', $value->id);
        }
    }

    #[Test]
    public function multi_parent_addresses_request_invokes_resolver_once_with_all_identities(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $roots    = [$this->customer('a'), $this->customer('b')];
        $includes = IncludeSet::fromQueryString('addresses');

        $graph = $pipeline->expandMany($roots, $includes, $this->context($includes));

        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingProfileResolver::$calls);

        $call = RecordingAddressesResolver::$calls[0];
        self::assertSame(2, $call['count']);
        self::assertSame(
            ['urn:semitexa:phase6e_customer:a', 'urn:semitexa:phase6e_customer:b'],
            array_map(fn (ResourceIdentity $i) => $i->urn(), $call['parents']),
        );

        foreach ($roots as $root) {
            $identity = ResourceIdentity::of('phase6e_customer', $root->id);
            self::assertTrue($graph->has($identity, 'addresses'));
            $list = $graph->lookup($identity, 'addresses');
            self::assertIsArray($list);
            self::assertCount(2, $list);
            self::assertSame($root->id . '-a1', $list[0]->id);
            self::assertSame($root->id . '-a2', $list[1]->id);
        }
    }

    #[Test]
    public function both_relations_requested_calls_each_resolver_exactly_once(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $roots    = [$this->customer('p'), $this->customer('q'), $this->customer('r')];
        $includes = IncludeSet::fromQueryString('profile,addresses');

        $pipeline->expandMany($roots, $includes, $this->context($includes));

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertCount(3, RecordingProfileResolver::$calls[0]['parents']);
        self::assertSame(3, RecordingAddressesResolver::$calls[0]['count']);
    }

    #[Test]
    public function resolver_is_not_called_when_includes_are_empty(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();

        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $roots = [$this->customer('1'), $this->customer('2')];
        $graph = $pipeline->expandMany($roots, IncludeSet::empty(), $this->context(IncludeSet::empty()));

        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertTrue($graph->isEmpty());
        self::assertSame($roots, $graph->roots);
    }

    #[Test]
    public function missing_parent_key_in_batch_yields_per_relation_default_for_each_parent(): void
    {
        $registry = $this->registry();

        $partialProfile = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                // Only respond for the FIRST parent. The second parent
                // is omitted from the map entirely.
                if (isset($parents[0])) {
                    $out[$parents[0]->urn()] = new ProfileResource(
                        id:  $parents[0]->id . '-profile',
                        bio: 'hit',
                    );
                }
                return $out;
            }
        };

        $partialAddresses = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                if (isset($parents[1])) {
                    $out[$parents[1]->urn()] = [
                        new AddressResource(id: $parents[1]->id . '-only', city: 'Kyiv', line1: 'X'),
                    ];
                }
                return $out;
            }
        };

        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class   => $partialProfile,
                RecordingAddressesResolver::class => $partialAddresses,
            ]),
        );

        $roots    = [$this->customer('1'), $this->customer('2')];
        $includes = IncludeSet::fromQueryString('profile,addresses');
        $graph    = $pipeline->expandMany($roots, $includes, $this->context($includes));

        $i1 = ResourceIdentity::of('phase6e_customer', '1');
        $i2 = ResourceIdentity::of('phase6e_customer', '2');

        // to-one: parent 1 hit, parent 2 missing → null overlay.
        self::assertInstanceOf(ProfileResource::class, $graph->lookup($i1, 'profile'));
        self::assertTrue($graph->has($i2, 'profile'));
        self::assertNull($graph->lookup($i2, 'profile'));

        // to-many: parent 1 missing → empty list, parent 2 hit.
        self::assertTrue($graph->has($i1, 'addresses'));
        self::assertSame([], $graph->lookup($i1, 'addresses'));
        $list = $graph->lookup($i2, 'addresses');
        self::assertIsArray($list);
        self::assertCount(1, $list);
        self::assertSame('2-only', $list[0]->id);
    }

    #[Test]
    public function extra_keys_in_batch_are_ignored(): void
    {
        $registry = $this->registry();

        $extras = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    $out[$p->urn()] = new ProfileResource(
                        id:  $p->id . '-profile',
                        bio: 'fixture',
                    );
                }
                // Unrelated extra keys must NOT leak into any parent's
                // overlay slot.
                $out['urn:semitexa:phase6e_customer:UNREQUESTED'] = new ProfileResource(
                    id:  'GHOST',
                    bio: 'should not appear',
                );
                $out['urn:semitexa:other:foo'] = new ProfileResource(
                    id:  'OTHER',
                    bio: 'should not appear',
                );
                return $out;
            }
        };

        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class   => $extras,
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
            ]),
        );

        $roots    = [$this->customer('1'), $this->customer('2')];
        $includes = IncludeSet::fromQueryString('profile');
        $graph    = $pipeline->expandMany($roots, $includes, $this->context($includes));

        $i1 = ResourceIdentity::of('phase6e_customer', '1');
        $i2 = ResourceIdentity::of('phase6e_customer', '2');

        self::assertSame('1-profile', $graph->lookup($i1, 'profile')->id);
        self::assertSame('2-profile', $graph->lookup($i2, 'profile')->id);

        // No "GHOST" or "OTHER" overlay entry for any requested parent.
        foreach ($graph->resolved as $value) {
            if ($value instanceof ProfileResource) {
                self::assertNotSame('GHOST', $value->id);
                self::assertNotSame('OTHER', $value->id);
            }
        }
    }

    #[Test]
    public function invalid_to_one_shape_in_multi_parent_batch_fails_loud(): void
    {
        $registry = $this->registry();

        $bad = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    $out[$p->urn()] = ['not', 'an', 'object'];
                }
                return $out;
            }
        };

        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class   => $bad,
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/to-one relation must be ResourceObjectInterface or null/');

        $pipeline->expandMany(
            [$this->customer('1'), $this->customer('2')],
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
    }

    #[Test]
    public function invalid_to_many_shape_in_multi_parent_batch_fails_loud(): void
    {
        $registry = $this->registry();

        $bad = new class implements RelationResolverInterface {
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
                RecordingProfileResolver::class   => new RecordingProfileResolver(),
                RecordingAddressesResolver::class => $bad,
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/cannot be null/');

        $pipeline->expandMany(
            [$this->customer('1'), $this->customer('2')],
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );
    }

    #[Test]
    public function non_string_batch_key_fails_loud(): void
    {
        $registry = $this->registry();

        $bad = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                return [42 => new ProfileResource(id: 'x', bio: 'y')];
            }
        };

        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class   => $bad,
                RecordingAddressesResolver::class => new RecordingAddressesResolver(),
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/string urn/');

        $pipeline->expandMany(
            [$this->customer('1'), $this->customer('2')],
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
    }

    #[Test]
    public function parent_order_in_input_is_preserved_in_roots_and_does_not_affect_call_count(): void
    {
        RecordingProfileResolver::reset();
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $orderA = [$this->customer('1'), $this->customer('2'), $this->customer('3')];
        $orderB = [$this->customer('3'), $this->customer('1'), $this->customer('2')];

        $includes = IncludeSet::fromQueryString('profile');

        $graphA = $pipeline->expandMany($orderA, $includes, $this->context($includes));
        $graphB = $pipeline->expandMany($orderB, $includes, $this->context($includes));

        // Two separate calls (one per pipeline invocation), each
        // bucket-batched once.
        self::assertCount(2, RecordingProfileResolver::$calls);

        // roots list preserves caller order verbatim.
        self::assertSame(['1', '2', '3'], array_map(fn ($c) => $c->id, $graphA->roots));
        self::assertSame(['3', '1', '2'], array_map(fn ($c) => $c->id, $graphB->roots));

        // Each parent's slot remains correctly populated regardless of
        // input order.
        foreach (['1', '2', '3'] as $id) {
            $identity = ResourceIdentity::of('phase6e_customer', $id);
            self::assertSame($id . '-profile', $graphA->lookup($identity, 'profile')->id);
            self::assertSame($id . '-profile', $graphB->lookup($identity, 'profile')->id);
        }
    }

    #[Test]
    public function root_dtos_are_not_mutated_by_multi_parent_expansion(): void
    {
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $roots = [$this->customer('1'), $this->customer('2'), $this->customer('3')];
        $snapshot = [];
        foreach ($roots as $r) {
            $snapshot[$r->id] = [
                'profile_data'  => $r->profile?->data,
                'addresses_ref' => $r->addresses,
                'addresses_data'=> $r->addresses->data,
            ];
        }

        $pipeline->expandMany(
            $roots,
            IncludeSet::fromQueryString('profile,addresses'),
            $this->context(IncludeSet::fromQueryString('profile,addresses')),
        );

        foreach ($roots as $r) {
            self::assertSame($snapshot[$r->id]['profile_data'], $r->profile?->data);
            self::assertSame($snapshot[$r->id]['addresses_ref'], $r->addresses);
            self::assertSame($snapshot[$r->id]['addresses_data'], $r->addresses->data);
        }
    }

    #[Test]
    public function repeated_calls_do_not_leak_overlay_state_between_runs(): void
    {
        RecordingProfileResolver::reset();
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $first   = [$this->customer('1'), $this->customer('2')];
        $second  = [$this->customer('99')];

        $includes = IncludeSet::fromQueryString('profile');

        $graph1 = $pipeline->expandMany($first,  $includes, $this->context($includes));
        $graph2 = $pipeline->expandMany($second, $includes, $this->context($includes));

        // Graph from the second run does not see parent identities
        // from the first run.
        $i1 = ResourceIdentity::of('phase6e_customer', '1');
        $i2 = ResourceIdentity::of('phase6e_customer', '2');
        $i99 = ResourceIdentity::of('phase6e_customer', '99');

        self::assertTrue($graph1->has($i1, 'profile'));
        self::assertTrue($graph1->has($i2, 'profile'));
        self::assertFalse($graph1->has($i99, 'profile'));

        self::assertFalse($graph2->has($i1, 'profile'));
        self::assertFalse($graph2->has($i2, 'profile'));
        self::assertTrue($graph2->has($i99, 'profile'));
    }

    #[Test]
    public function each_parent_gets_its_own_overlay_with_no_data_leakage(): void
    {
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $roots = [$this->customer('alice'), $this->customer('bob'), $this->customer('carol')];
        $includes = IncludeSet::fromQueryString('addresses');
        $graph = $pipeline->expandMany($roots, $includes, $this->context($includes));

        $alice = ResourceIdentity::of('phase6e_customer', 'alice');
        $bob   = ResourceIdentity::of('phase6e_customer', 'bob');
        $carol = ResourceIdentity::of('phase6e_customer', 'carol');

        // Each parent's resolved list contains only items derived from
        // that parent's own id (suffix `-a1`/`-a2`).
        $aliceList = $graph->lookup($alice, 'addresses');
        $bobList   = $graph->lookup($bob, 'addresses');
        $carolList = $graph->lookup($carol, 'addresses');

        foreach ([
            ['alice', $aliceList],
            ['bob',   $bobList],
            ['carol', $carolList],
        ] as [$id, $list]) {
            self::assertIsArray($list);
            self::assertCount(2, $list);
            foreach ($list as $address) {
                self::assertStringStartsWith($id . '-', $address->id);
            }
        }
    }

    #[Test]
    public function single_parent_renders_byte_identical_overlay_via_json_renderer(): void
    {
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root     = $this->customer('7');
        $includes = IncludeSet::fromQueryString('profile,addresses');
        $ctx      = new RenderContext(profile: RenderProfile::Json, includes: $includes);

        // Single-parent path via expand() — Phase 6e behaviour.
        $graph1 = $pipeline->expand($root, $includes, $ctx);
        $out1   = $renderer->render($root, $ctx->withResolved($graph1));

        // Same single parent through expandMany() — Phase 6f path.
        $graph2 = $pipeline->expandMany([$root], $includes, $ctx);
        $out2   = $renderer->render($root, $ctx->withResolved($graph2));

        self::assertSame($out1, $out2);
    }

    #[Test]
    public function expand_many_rejects_associative_array_input(): void
    {
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/list of ResourceObjectInterface/');

        /** @phpstan-ignore-next-line — deliberate misuse for the guard. */
        $pipeline->expandMany(
            ['a' => $this->customer('1')],
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
    }

    #[Test]
    public function expand_many_rejects_non_resource_entries(): void
    {
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/parent at index 1 is/');

        /** @phpstan-ignore-next-line — deliberate misuse for the guard. */
        $pipeline->expandMany(
            [$this->customer('1'), 'not-a-resource'],
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );
    }

    #[Test]
    public function multi_parent_graph_supports_per_parent_renderer_lookup(): void
    {
        $registry = $this->registry();
        $pipeline = $this->pipeline($registry);
        $renderer = JsonResourceRenderer::forTesting($registry);

        $roots    = [$this->customer('1'), $this->customer('2')];
        $includes = IncludeSet::fromQueryString('profile,addresses');
        $ctx      = new RenderContext(profile: RenderProfile::Json, includes: $includes);

        $graph = $pipeline->expandMany($roots, $includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        // Render each root individually; each render sees its OWN
        // resolved overlay slots, not the other parent's.
        $out1 = $renderer->render($roots[0], $ctx);
        $out2 = $renderer->render($roots[1], $ctx);

        self::assertArrayHasKey('profile', $out1);
        self::assertArrayHasKey('profile', $out2);

        self::assertSame('1-profile', $out1['profile']['data']['id']);
        self::assertSame('2-profile', $out2['profile']['data']['id']);

        self::assertSame('1-a1', $out1['addresses']['data'][0]['id']);
        self::assertSame('2-a1', $out2['addresses']['data'][0]['id']);
    }
}
