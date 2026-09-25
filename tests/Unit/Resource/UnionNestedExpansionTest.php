<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use Semitexa\Core\Tests\Support\StaticState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResolvedResourceGraph;
use Semitexa\Core\Resource\ResourceExpansionPipeline;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UnionBotResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UnionCommentResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UnionKeyedBotResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UnionUserResource;

/**
 * Nested expansion under a `#[ResourceUnion]` relation must walk each
 * resolved child with its own class's metadata. `author.profile` only
 * exists on the user target, so bot authors must neither reach the
 * profile resolver nor break identity extraction.
 */
final class UnionNestedExpansionTest extends TestCase
{
    /** @var list<array{class: class-string, values: array<string, mixed>}> */
    private array $staticState;

    protected function setUp(): void
    {
        // Snapshot the static call logs BEFORE clearing them, so the next test
        // finds them as it would have without this one.
        $this->staticState = [
            StaticState::snapshot(RecordingProfileResolver::class),
        ];
        RecordingProfileResolver::reset();
    }

    protected function tearDown(): void
    {
        array_map([StaticState::class, 'restore'], $this->staticState);
    }

    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        foreach ([
            PreferencesResource::class,
            ProfileResource::class,
            UnionUserResource::class,
            UnionBotResource::class,
            UnionKeyedBotResource::class,
            UnionCommentResource::class,
        ] as $class) {
            $registry->register($extractor->extract($class));
        }
        return $registry;
    }

    /** @param list<string> $commentIds */
    private function expand(array $commentIds): ResolvedResourceGraph
    {
        $registry = $this->registry();
        $includes = IncludeSet::fromQueryString('author.profile');
        IncludeValidator::forTesting($registry)
            ->validate($includes, $registry->require(UnionCommentResource::class));

        $container = new class implements ContainerInterface {
            public function get(string $id): object
            {
                return new $id();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        return ResourceExpansionPipeline::forTesting($registry, $container)->expandMany(
            array_map(static fn (string $id): UnionCommentResource => new UnionCommentResource(id: $id), $commentIds),
            $includes,
            new RenderContext(profile: RenderProfile::Json, includes: $includes),
        );
    }

    /** @return list<string> */
    private function profileResolverParentUrns(): array
    {
        $urns = [];
        foreach (RecordingProfileResolver::$calls as $call) {
            foreach ($call['parents'] as $parent) {
                $urns[] = $parent->urn();
            }
        }
        return $urns;
    }

    #[Test]
    public function only_children_of_the_target_declaring_the_nested_relation_reach_its_resolver(): void
    {
        $graph = $this->expand(['u1', 'b1', 'u2']);

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertSame(
            [
                ResourceIdentity::of('union_user', 'u1-author')->urn(),
                ResourceIdentity::of('union_user', 'u2-author')->urn(),
            ],
            $this->profileResolverParentUrns(),
        );
        self::assertTrue($graph->has(ResourceIdentity::of('union_user', 'u1-author'), 'profile'));
        // b1's author is a BOT; asking about a union_user b1-author could never be true.
        self::assertFalse($graph->has(ResourceIdentity::of('union_bot', 'b1-author'), 'profile'));
    }

    #[Test]
    public function child_whose_id_property_differs_from_the_first_target_is_skipped_not_fatal(): void
    {
        $graph = $this->expand(['u1', 'k1']);

        self::assertSame(
            [ResourceIdentity::of('union_user', 'u1-author')->urn()],
            $this->profileResolverParentUrns(),
        );
        self::assertTrue($graph->has(ResourceIdentity::of('union_user', 'u1-author'), 'profile'));
    }
}
