<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Exception\InvalidResolvedRelationException;
use Semitexa\Core\Resource\Exception\NestedIncludeDepthExceededException;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceExpansionPipeline;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6g: nested resolver expansion.
 *
 * `?include=profile.preferences` walks two resolver levels:
 *
 *   1. `RecordingProfileResolver` produces a `ProfileResource` per
 *      Customer identity.
 *   2. `RecordingPreferencesResolver` produces a `PreferencesResource`
 *      per resolved `ProfileResource` identity.
 *
 * Resolver invocation invariants (single-parent customer in this
 * suite; multi-parent batching is verified by Phase 6f):
 *
 *   - `?include=profile`               → only the profile resolver fires.
 *   - `?include=addresses`             → only the addresses resolver fires.
 *   - `?include=profile,addresses`     → exactly two resolver calls.
 *   - `?include=profile.preferences`   → exactly two resolver calls,
 *     one per level (no addresses).
 *   - `?include=profile.preferences,addresses` → three calls, one per
 *     bucket (top-level addresses + top-level profile + nested
 *     preferences).
 */
final class Phase6gNestedExpansionTest extends TestCase
{
    protected function setUp(): void
    {
        // Recording resolvers carry a static `$calls` array — reset
        // before every test so call-count assertions stay
        // order-independent under the test runner.
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();
        RecordingPreferencesResolver::reset();
    }

    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerWithBothResolvedResource::class));
        return $registry;
    }

    private function root(string $id = '7'): CustomerWithBothResolvedResource
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

    private function pipeline(): ResourceExpansionPipeline
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();
        RecordingPreferencesResolver::reset();

        return ResourceExpansionPipeline::forTesting(
            $this->registry(),
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
    }

    // ----- Pipeline-level resolver invocation contract -----------------

    #[Test]
    public function include_profile_alone_does_not_trigger_nested_resolver(): void
    {
        $pipeline = $this->pipeline();

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function include_addresses_alone_does_not_trigger_profile_or_nested_resolver(): void
    {
        $pipeline = $this->pipeline();

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('addresses'),
            $this->context(IncludeSet::fromQueryString('addresses')),
        );

        self::assertCount(1, RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function dotted_include_implies_parent_and_invokes_both_resolvers_in_order(): void
    {
        $pipeline = $this->pipeline();

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function nested_resolver_receives_profile_identities_not_customer_identities(): void
    {
        $pipeline = $this->pipeline();

        $pipeline->expand(
            $this->root('42'),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        $nestedCall = RecordingPreferencesResolver::$calls[0];
        self::assertCount(1, $nestedCall['parents']);
        $childIdentity = $nestedCall['parents'][0];
        self::assertSame('profile', $childIdentity->type);
        // RecordingProfileResolver builds `ProfileResource(id: $parent->id . '-profile')`,
        // so the customer id `42` becomes profile id `42-profile`.
        self::assertSame('42-profile', $childIdentity->id);
    }

    #[Test]
    public function nested_overlay_slot_is_keyed_by_profile_identity_and_field_name(): void
    {
        $pipeline = $this->pipeline();

        $graph = $pipeline->expand(
            $this->root('7'),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        $profileIdentity = ResourceIdentity::of('profile', '7-profile');
        self::assertTrue($graph->has($profileIdentity, 'preferences'));
        $value = $graph->lookup($profileIdentity, 'preferences');
        self::assertInstanceOf(PreferencesResource::class, $value);
        self::assertSame('prefs-7-profile', $value->id);
    }

    #[Test]
    public function include_profile_preferences_and_addresses_calls_each_resolver_exactly_once(): void
    {
        $pipeline = $this->pipeline();

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile.preferences,addresses'),
            $this->context(IncludeSet::fromQueryString('profile.preferences,addresses')),
        );

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertCount(1, RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function null_parent_resolution_skips_nested_resolver(): void
    {
        $registry = $this->registry();
        $nullProfile = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    $out[$p->urn()] = null; // resolver explicitly says "absent".
                }
                return $out;
            }
        };
        RecordingPreferencesResolver::reset();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => $nullProfile,
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        self::assertSame(
            [],
            RecordingPreferencesResolver::$calls,
            'Nested resolver must not run when parent resolution returned null.',
        );
    }

    #[Test]
    public function missing_parent_key_in_top_level_batch_skips_nested_resolver_for_that_parent(): void
    {
        $registry = $this->registry();
        $missingMap = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                return []; // omit every parent → "absent" by Phase 6d rules.
            }
        };
        RecordingPreferencesResolver::reset();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => $missingMap,
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function nested_resolver_invalid_to_one_shape_fails_loud(): void
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
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => $bad,
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/to-one relation must be ResourceObjectInterface or null/');

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );
    }

    #[Test]
    public function nested_resolver_wrong_target_type_fails_loud(): void
    {
        $registry = $this->registry();
        $bad = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $out = [];
                foreach ($parents as $p) {
                    // Wrong target — returning an AddressResource where a
                    // PreferencesResource is expected.
                    $out[$p->urn()] = new AddressResource(id: 'x', city: 'c', line1: 'l');
                }
                return $out;
            }
        };
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => $bad,
            ]),
        );

        $this->expectException(InvalidResolvedRelationException::class);
        $this->expectExceptionMessageMatches('/expected target type/');

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );
    }

    #[Test]
    public function three_segment_dotted_token_is_rejected_with_depth_exception(): void
    {
        $pipeline = $this->pipeline();

        $this->expectException(NestedIncludeDepthExceededException::class);
        $this->expectExceptionMessageMatches('/exceeds the maximum supported depth/');

        $pipeline->expand(
            $this->root(),
            IncludeSet::fromQueryString('profile.preferences.theme'),
            $this->context(IncludeSet::fromQueryString('profile.preferences.theme')),
        );
    }

    #[Test]
    public function repeated_nested_expansion_does_not_leak_state_between_runs(): void
    {
        $pipeline = $this->pipeline();

        $g1 = $pipeline->expand(
            $this->root('1'),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );
        $g2 = $pipeline->expand(
            $this->root('2'),
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        $p1 = ResourceIdentity::of('profile', '1-profile');
        $p2 = ResourceIdentity::of('profile', '2-profile');
        self::assertTrue($g1->has($p1, 'preferences'));
        self::assertFalse($g1->has($p2, 'preferences'));
        self::assertFalse($g2->has($p1, 'preferences'));
        self::assertTrue($g2->has($p2, 'preferences'));
    }

    #[Test]
    public function root_dto_and_resolved_profile_dto_are_not_mutated_by_nested_expansion(): void
    {
        $pipeline = $this->pipeline();

        $root = $this->root();
        $rootProfileRef = $root->profile;
        $rootProfileData = $root->profile?->data;

        $pipeline->expand(
            $root,
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        self::assertSame($rootProfileRef, $root->profile);
        self::assertSame($rootProfileData, $root->profile?->data);
    }

    #[Test]
    public function single_level_behavior_is_byte_identical_to_phase_6e_for_no_nested_token(): void
    {
        // Under `?include=profile,addresses` (no dotted token), the
        // overlay should be exactly the Phase 6e shape: two top-level
        // slots, no preferences slot. This pins the regression of
        // accidentally triggering nested expansion on plain top-level
        // includes.
        $pipeline = $this->pipeline();

        $graph = $pipeline->expand(
            $this->root('77'),
            IncludeSet::fromQueryString('profile,addresses'),
            $this->context(IncludeSet::fromQueryString('profile,addresses')),
        );

        $rootIdent = ResourceIdentity::of('phase6e_customer', '77');
        self::assertTrue($graph->has($rootIdent, 'profile'));
        self::assertTrue($graph->has($rootIdent, 'addresses'));

        $profileIdent = ResourceIdentity::of('profile', '77-profile');
        self::assertFalse(
            $graph->has($profileIdent, 'preferences'),
            'no dotted token → no nested overlay slot',
        );
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    // ----- Renderer integration ---------------------------------------

    #[Test]
    public function json_renderer_consumes_nested_overlay(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root = $this->root('7');
        $ctx  = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('profile.preferences'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        self::assertArrayHasKey('profile', $output);
        $profileEnvelope = $output['profile'];
        self::assertSame('profile', $profileEnvelope['type']);
        self::assertArrayHasKey('data', $profileEnvelope);

        $profileData = $profileEnvelope['data'];
        self::assertSame('7-profile', $profileData['id']);

        // Nested overlay rendered through the recursive overlay
        // pathway: ProfileResource->preferences was null on the bare
        // DTO, but the overlay supplied PreferencesResource.
        self::assertArrayHasKey('preferences', $profileData);
        self::assertNotNull($profileData['preferences']);
        self::assertSame('phase6g_preferences', $profileData['preferences']['type']);
        self::assertSame('prefs-7-profile', $profileData['preferences']['id']);
        self::assertSame('prefs-7-profile', $profileData['preferences']['data']['id']);
        self::assertSame('dark-7-profile', $profileData['preferences']['data']['theme']);
    }

    #[Test]
    public function json_renderer_omits_nested_data_when_only_top_level_include_requested(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root = $this->root('7');
        $ctx  = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('profile'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);
        self::assertNull(
            $output['profile']['data']['preferences'],
            '?include=profile alone must not embed nested preferences data.',
        );
    }

    #[Test]
    public function jsonld_renderer_consumes_nested_overlay(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
        $renderer = JsonLdResourceRenderer::forTesting($registry);

        $root = $this->root('9');
        $ctx  = new RenderContext(
            profile:  RenderProfile::JsonLd,
            includes: IncludeSet::fromQueryString('profile.preferences'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        self::assertIsArray($output['profile']);
        $profileNode = $output['profile'];
        self::assertSame('profile', $profileNode['@type']);
        // Nested preferences node embedded under the profile node.
        self::assertArrayHasKey('preferences', $profileNode);
        self::assertSame('phase6g_preferences', $profileNode['preferences']['@type']);
        self::assertSame('dark-9-profile', $profileNode['preferences']['theme']);
    }

    #[Test]
    public function graphql_renderer_consumes_nested_overlay(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
        $renderer = GraphqlResourceRenderer::forTesting($registry);

        $root = $this->root('5');
        $ctx  = new RenderContext(
            profile:  RenderProfile::GraphQL,
            includes: IncludeSet::fromQueryString('profile.preferences'),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);

        $rootField   = array_key_first($output['data']);
        $profileNode = $output['data'][$rootField]['profile'];
        self::assertIsArray($profileNode);
        self::assertSame('5-profile', $profileNode['id']);
        self::assertArrayHasKey('preferences', $profileNode);
        self::assertSame('prefs-5-profile', $profileNode['preferences']['id']);
        self::assertSame('dark-5-profile', $profileNode['preferences']['theme']);
    }

    #[Test]
    public function existing_no_include_output_remains_reference_only_for_profile(): void
    {
        $registry = $this->registry();
        $pipeline = ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root = $this->root('7');
        $ctx  = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::empty(),
        );
        $graph = $pipeline->expand($root, $ctx->includes, $ctx);
        $ctx   = $ctx->withResolved($graph);

        $output = $renderer->render($root, $ctx);
        self::assertArrayNotHasKey('data', $output['profile']);
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }
}
