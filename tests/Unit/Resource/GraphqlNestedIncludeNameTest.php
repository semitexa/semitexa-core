<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\UnloadedRelationException;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResolvedResourceGraph;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AliasProfileCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * The GraphQL renderer must hand nested includes down by the relation's
 * include name, not its property name. `AliasProfileCustomerResource`
 * declares property `authorProfile` with include `profile`, so for
 * `profile.preferences` the nested profile must still see `preferences`
 * as requested and refuse to render it link-only — the same contract the
 * JSON renderer enforces.
 */
final class GraphqlNestedIncludeNameTest extends TestCase
{
    private ResourceMetadataRegistry $registry;

    protected function setUp(): void
    {
        $extractor      = new ResourceMetadataExtractor();
        $this->registry = ResourceMetadataRegistry::forTesting($extractor);
        $this->registry->register($extractor->extract(PreferencesResource::class));
        $this->registry->register($extractor->extract(ProfileResource::class));
        $this->registry->register($extractor->extract(AliasProfileCustomerResource::class));
    }

    private function profileWithUnloadedPreferences(): ProfileResource
    {
        return new ProfileResource(
            id:          'p1',
            bio:         'bio',
            preferences: ResourceRef::to(ResourceIdentity::of('preferences', 'pr1'), '/profiles/p1/preferences'),
        );
    }

    private function includes(): IncludeSet
    {
        return IncludeSet::fromQueryString('profile.preferences');
    }

    #[Test]
    public function json_renderer_rejects_unloaded_nested_relation_under_aliased_include(): void
    {
        $customer = new AliasProfileCustomerResource(
            id:            '1',
            authorProfile: ResourceRef::embed(ResourceIdentity::of('profile', 'p1'), $this->profileWithUnloadedPreferences()),
        );

        $this->expectException(UnloadedRelationException::class);
        JsonResourceRenderer::forTesting($this->registry)->render(
            $customer,
            new RenderContext(profile: RenderProfile::Json, includes: $this->includes()),
        );
    }

    #[Test]
    public function graphql_renderer_rejects_unloaded_nested_relation_under_aliased_include(): void
    {
        $customer = new AliasProfileCustomerResource(
            id:            '1',
            authorProfile: ResourceRef::embed(ResourceIdentity::of('profile', 'p1'), $this->profileWithUnloadedPreferences()),
        );

        $this->expectException(UnloadedRelationException::class);
        GraphqlResourceRenderer::forTesting($this->registry)->render(
            $customer,
            new RenderContext(profile: RenderProfile::GraphQL, includes: $this->includes()),
        );
    }

    #[Test]
    public function graphql_renderer_rejects_unloaded_nested_relation_under_aliased_include_from_overlay(): void
    {
        $customer = new AliasProfileCustomerResource(id: '1', authorProfile: null);
        $includes = $this->includes();
        $graph    = new ResolvedResourceGraph($customer, $includes, [
            ResolvedResourceGraph::formatKey(ResourceIdentity::of('alias_profile_customer', '1')->urn(), 'authorProfile')
                => $this->profileWithUnloadedPreferences(),
        ]);

        $this->expectException(UnloadedRelationException::class);
        GraphqlResourceRenderer::forTesting($this->registry)->render(
            $customer,
            (new RenderContext(profile: RenderProfile::GraphQL, includes: $includes))->withResolved($graph),
        );
    }
}
