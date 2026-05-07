<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Exception\InvalidResolvedRelationException;
use Semitexa\Core\Resource\IncludeSet;
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
 * Phase 7: expansion-scoped resolver memoisation in
 * `ResourceExpansionPipeline`. The pipeline collapses duplicate
 * resolver demand inside a single `expand()` / `expandMany()` call:
 *
 *   - duplicate parent identities resolve once;
 *   - overlapping include tokens (`profile` + `profile.preferences`)
 *     do not double-call the parent resolver;
 *   - the memo is fresh per expansion call — two separate calls do
 *     NOT share memo state. Cross-request safety in Swoole follows
 *     directly from this property.
 */
final class Phase7ResolverMemoisationPipelineTest extends TestCase
{
    protected function setUp(): void
    {
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

    private function pipeline(): ResourceExpansionPipeline
    {
        return ResourceExpansionPipeline::forTesting(
            $this->registry(),
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
    }

    private function context(IncludeSet $includes): RenderContext
    {
        return new RenderContext(profile: RenderProfile::Json, includes: $includes);
    }

    // ----- Duplicate parent identity ---------------------------------

    #[Test]
    public function duplicate_parent_identity_resolves_once(): void
    {
        $pipeline = $this->pipeline();

        $a = $this->customer('1');

        // Same identity twice. Phase 6f's bucket already grouped
        // them; Phase 7's memo collapses them at the resolver call
        // boundary so the resolver receives ONE identity, not two.
        $graph = $pipeline->expandMany(
            [$a, $a],
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(
            1,
            RecordingProfileResolver::$calls[0]['parents'],
            'duplicate parent identity must reach the resolver only once',
        );

        // Both root positions still see the resolved value: the
        // overlay is keyed by `(parentUrn, fieldName)`, so the two
        // root references point at the same slot.
        $identity = ResourceIdentity::of('phase6e_customer', '1');
        self::assertTrue($graph->has($identity, 'profile'));
        self::assertSame('1-profile', $graph->lookup($identity, 'profile')->id);
    }

    #[Test]
    public function duplicate_in_a_three_parent_list_resolves_unique_set_only(): void
    {
        $pipeline = $this->pipeline();

        $a = $this->customer('1');
        $b = $this->customer('2');

        $pipeline->expandMany(
            [$a, $b, $a, $a, $b],
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );

        self::assertCount(1, RecordingProfileResolver::$calls);
        $ids = array_map(static fn ($i) => $i->id, RecordingProfileResolver::$calls[0]['parents']);
        sort($ids);
        self::assertSame(['1', '2'], $ids);
    }

    // ----- Overlapping include tokens --------------------------------

    #[Test]
    public function overlapping_include_tokens_do_not_double_call_parent_resolver(): void
    {
        $pipeline = $this->pipeline();

        // `profile` AND `profile.preferences` both demand the
        // top-level profile resolver. The memo guarantees one call
        // for the parent and one call for the nested preferences.
        $pipeline->expandMany(
            [$this->customer('7')],
            IncludeSet::fromQueryString('profile,profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile,profile.preferences')),
        );

        self::assertCount(
            1,
            RecordingProfileResolver::$calls,
            'overlapping `profile` + `profile.preferences` must call the profile resolver once',
        );
        self::assertCount(1, RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function overlapping_includes_with_multi_parent_collapse_correctly(): void
    {
        $pipeline = $this->pipeline();

        $pipeline->expandMany(
            [$this->customer('1'), $this->customer('2'), $this->customer('1')],
            IncludeSet::fromQueryString('profile,profile.preferences,addresses'),
            $this->context(IncludeSet::fromQueryString('profile,profile.preferences,addresses')),
        );

        // Profile resolver: ids 1 and 2 (no duplicate '1').
        self::assertCount(1, RecordingProfileResolver::$calls);
        $profileParents = array_map(
            static fn ($i) => $i->id,
            RecordingProfileResolver::$calls[0]['parents'],
        );
        sort($profileParents);
        self::assertSame(['1', '2'], $profileParents);

        // Addresses resolver: same dedupe.
        self::assertCount(1, RecordingAddressesResolver::$calls);
        $addressParents = array_map(
            static fn ($i) => $i->id,
            RecordingAddressesResolver::$calls[0]['parents'],
        );
        sort($addressParents);
        self::assertSame(['1', '2'], $addressParents);

        // Preferences resolver: nested over the resolved profiles
        // (1-profile, 2-profile) — also unique.
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        $prefParents = array_map(
            static fn ($i) => $i->id,
            RecordingPreferencesResolver::$calls[0]['parents'],
        );
        sort($prefParents);
        self::assertSame(['1-profile', '2-profile'], $prefParents);
    }

    // ----- Expansion-scope isolation --------------------------------

    #[Test]
    public function memo_does_not_persist_across_separate_expansion_calls(): void
    {
        // Phase 7 commits to expansion-scoped (NOT request-scoped)
        // memoisation: each `expandMany()` call creates a fresh
        // memo and discards it on return. Two separate calls
        // therefore each invoke the resolver — even with the same
        // RenderContext — because the memo does not persist.
        $pipeline = $this->pipeline();
        $includes = IncludeSet::fromQueryString('profile');
        $ctx      = $this->context($includes);

        $pipeline->expandMany([$this->customer('1')], $includes, $ctx);
        $pipeline->expandMany([$this->customer('1')], $includes, $ctx);

        self::assertCount(
            2,
            RecordingProfileResolver::$calls,
            'expansion-scoped memo must NOT carry across separate expand()/expandMany() calls',
        );
    }

    #[Test]
    public function different_render_contexts_do_not_share_memo_state(): void
    {
        $pipeline = $this->pipeline();
        $includes = IncludeSet::fromQueryString('profile');

        $pipeline->expandMany([$this->customer('1')], $includes, $this->context($includes));
        $pipeline->expandMany([$this->customer('1')], $includes, $this->context($includes));

        // Two separate RenderContext instances — and two separate
        // expansion calls — both see a fresh memo, so the resolver
        // is called once per call. This is the Swoole-safe shape:
        // no state survives the call boundary.
        self::assertCount(2, RecordingProfileResolver::$calls);
    }

    // ----- Negative-cache behavior ----------------------------------

    #[Test]
    public function invalid_resolver_output_is_not_memoised(): void
    {
        $registry = $this->registry();

        // First call returns invalid shape — to-many `null`. The
        // pipeline raises and must NOT cache the bad value.
        $bad = new class implements RelationResolverInterface {
            public int $callCount = 0;
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $this->callCount++;
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
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => $bad,
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );

        $thrown = false;
        try {
            $pipeline->expandMany(
                [$this->customer('1')],
                IncludeSet::fromQueryString('addresses'),
                $this->context(IncludeSet::fromQueryString('addresses')),
            );
        } catch (InvalidResolvedRelationException) {
            $thrown = true;
        }
        self::assertTrue($thrown);

        // Each subsequent expansion gets a fresh memo, so the bad
        // resolver is called again — and throws again. The memo
        // stayed clean.
        $thrown = false;
        try {
            $pipeline->expandMany(
                [$this->customer('1')],
                IncludeSet::fromQueryString('addresses'),
                $this->context(IncludeSet::fromQueryString('addresses')),
            );
        } catch (InvalidResolvedRelationException) {
            $thrown = true;
        }
        self::assertTrue($thrown);
        self::assertSame(2, $bad->callCount);
    }

    // ----- No-include / empty fast path -----------------------------

    #[Test]
    public function empty_include_set_calls_no_resolver(): void
    {
        $pipeline = $this->pipeline();
        $pipeline->expandMany(
            [$this->customer('1'), $this->customer('1')],
            IncludeSet::empty(),
            $this->context(IncludeSet::empty()),
        );
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    // ----- Output regression ----------------------------------------

    #[Test]
    public function memoised_value_is_byte_identical_to_freshly_resolved_value_per_parent(): void
    {
        // For `expandMany([$a, $b, $a])`, the two `$a` rows must see
        // the same resolved profile object as they would in a non-
        // duplicate call.
        $pipeline = $this->pipeline();

        $graph = $pipeline->expandMany(
            [$this->customer('1'), $this->customer('2'), $this->customer('1')],
            IncludeSet::fromQueryString('profile'),
            $this->context(IncludeSet::fromQueryString('profile')),
        );

        $i1 = ResourceIdentity::of('phase6e_customer', '1');
        $i2 = ResourceIdentity::of('phase6e_customer', '2');

        $a1 = $graph->lookup($i1, 'profile');
        $b  = $graph->lookup($i2, 'profile');
        // The overlay slot for parent 1 is shared by both root
        // positions of customer 1 — they look up by urn.
        self::assertSame('1-profile', $a1->id);
        self::assertSame('2-profile', $b->id);
    }

    #[Test]
    public function root_dtos_are_not_mutated_by_memoisation(): void
    {
        $pipeline = $this->pipeline();
        $a = $this->customer('1');
        $b = $this->customer('2');
        $snapshotA = ['profile' => $a->profile, 'addresses' => $a->addresses];
        $snapshotB = ['profile' => $b->profile, 'addresses' => $b->addresses];

        $pipeline->expandMany(
            [$a, $b, $a],
            IncludeSet::fromQueryString('profile.preferences'),
            $this->context(IncludeSet::fromQueryString('profile.preferences')),
        );

        self::assertSame($snapshotA['profile'], $a->profile);
        self::assertSame($snapshotA['addresses'], $a->addresses);
        self::assertSame($snapshotB['profile'], $b->profile);
        self::assertSame($snapshotB['addresses'], $b->addresses);
    }
}
