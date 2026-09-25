<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\GraphqlSelectionParser;
use Semitexa\Core\Resource\GraphqlSelectionToIncludeSet;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceExpansionPipeline;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CamelIncludeCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * An explicit include name with uppercase letters (`userProfile`) must
 * resolve through every entry point: the validator already matches it
 * case-insensitively, so the pipeline and the GraphQL bridge must too.
 */
final class IncludeNameCaseTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingProfileResolver::reset();
        RecordingPreferencesResolver::reset();
    }

    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CamelIncludeCustomerResource::class));
        return $registry;
    }

    private function pipeline(ResourceMetadataRegistry $registry): ResourceExpansionPipeline
    {
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

        return ResourceExpansionPipeline::forTesting($registry, $container);
    }

    private function root(): CamelIncludeCustomerResource
    {
        return new CamelIncludeCustomerResource(
            id: '7',
            userProfile: ResourceRef::to(ResourceIdentity::of('profile', '7-profile'), '/c/7/profile'),
        );
    }

    #[Test]
    public function camel_case_query_include_with_nested_token_resolves_both_levels(): void
    {
        $registry = $this->registry();
        $includes = IncludeSet::fromQueryString('userProfile.preferences');
        IncludeValidator::forTesting($registry)
            ->validate($includes, $registry->require(CamelIncludeCustomerResource::class));

        $graph = $this->pipeline($registry)->expand(
            $this->root(),
            $includes,
            new RenderContext(profile: RenderProfile::Json, includes: $includes),
        );

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertTrue($graph->has(ResourceIdentity::of('camel_include_customer', '7'), 'userProfile'));
    }

    #[Test]
    public function camel_case_graphql_selection_resolves_the_relation(): void
    {
        $registry  = $this->registry();
        $rootField = (new GraphqlSelectionParser())
            ->parse('{ customer { userProfile { id } } }')
            ->singleRootField();
        $includes = GraphqlSelectionToIncludeSet::forTesting($registry)
            ->translate($rootField, $registry->require(CamelIncludeCustomerResource::class));

        self::assertTrue($includes->has('userProfile'));

        $graph = $this->pipeline($registry)->expand(
            $this->root(),
            $includes,
            new RenderContext(profile: RenderProfile::GraphQL, includes: $includes),
        );

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertTrue($graph->has(ResourceIdentity::of('camel_include_customer', '7'), 'userProfile'));
    }
}
