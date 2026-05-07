<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResolvedResourceGraph;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithResolvedProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * Phase 6d: each renderer must read the resolved overlay for
 * resolver-backed relations. The overlay is built by tests directly to
 * isolate renderer behaviour from pipeline behaviour.
 */
final class Phase6dRendererOverlayTest extends TestCase
{
    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerWithResolvedProfileResource::class));
        return $registry;
    }

    private function buildRoot(): CustomerWithResolvedProfileResource
    {
        return new CustomerWithResolvedProfileResource(
            id: '99',
            profile: ResourceRef::to(
                ResourceIdentity::of('profile', '99-profile'),
                '/x/99/profile',
            ),
            addresses: ResourceRefList::to('/x/99/addresses'),
        );
    }

    /** @return array{rootIdentity: ResourceIdentity, root: CustomerWithResolvedProfileResource, graph: ResolvedResourceGraph} */
    private function buildGraphWithProfile(): array
    {
        $root         = $this->buildRoot();
        $rootIdentity = ResourceIdentity::of('phase6d_customer', '99');
        $resolved     = [
            ResolvedResourceGraph::formatKey($rootIdentity->urn(), 'profile')
                => new ProfileResource(id: '99-profile', bio: 'overlay bio'),
        ];
        $graph = new ResolvedResourceGraph(
            $root,
            IncludeSet::fromQueryString('profile'),
            $resolved,
        );
        return ['rootIdentity' => $rootIdentity, 'root' => $root, 'graph' => $graph];
    }

    #[Test]
    public function json_renderer_uses_graph_resolved_profile(): void
    {
        $registry = $this->registry();
        $renderer = JsonResourceRenderer::forTesting($registry);
        ['root' => $root, 'graph' => $graph] = $this->buildGraphWithProfile();

        $ctx = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('profile'),
            resolved: $graph,
        );
        $output = $renderer->render($root, $ctx);

        self::assertIsArray($output['profile']);
        self::assertArrayHasKey('data', $output['profile']);
        self::assertSame('overlay bio', $output['profile']['data']['bio']);
        self::assertSame('99-profile', $output['profile']['data']['id']);
    }

    #[Test]
    public function jsonld_renderer_uses_graph_resolved_profile(): void
    {
        $registry = $this->registry();
        $renderer = JsonLdResourceRenderer::forTesting($registry);
        ['root' => $root, 'graph' => $graph] = $this->buildGraphWithProfile();

        $ctx = new RenderContext(
            profile:  RenderProfile::JsonLd,
            includes: IncludeSet::fromQueryString('profile'),
            resolved: $graph,
        );
        $output = $renderer->render($root, $ctx);

        self::assertIsArray($output['profile']);
        self::assertSame('overlay bio', $output['profile']['bio']);
        self::assertSame('profile', $output['profile']['@type']);
    }

    #[Test]
    public function graphql_renderer_uses_graph_resolved_profile(): void
    {
        $registry = $this->registry();
        $renderer = GraphqlResourceRenderer::forTesting($registry);
        ['root' => $root, 'graph' => $graph] = $this->buildGraphWithProfile();

        $ctx = new RenderContext(
            profile:  RenderProfile::GraphQL,
            includes: IncludeSet::fromQueryString('profile'),
            resolved: $graph,
        );
        $output = $renderer->render($root, $ctx);

        self::assertIsArray($output['data']);
        $rootField = array_key_first($output['data']);
        self::assertSame('phase6d_customer', $rootField);
        self::assertIsArray($output['data'][$rootField]['profile']);
        self::assertSame('overlay bio', $output['data'][$rootField]['profile']['bio']);
    }

    #[Test]
    public function renderer_falls_through_when_overlay_does_not_have_relation(): void
    {
        $registry = $this->registry();
        $renderer = JsonResourceRenderer::forTesting($registry);

        $root = $this->buildRoot();
        $ctx  = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::empty(), // ← no include requested
            resolved: ResolvedResourceGraph::withRootOnly($root, IncludeSet::empty()),
        );
        $output = $renderer->render($root, $ctx);

        // No overlay entry for `profile`, no include requested → link-only.
        self::assertSame('99-profile', $output['profile']['id']);
        self::assertArrayNotHasKey('data', $output['profile']);
    }

    #[Test]
    public function renderer_treats_overlay_null_as_link_only(): void
    {
        $registry     = $this->registry();
        $renderer     = JsonResourceRenderer::forTesting($registry);
        $root         = $this->buildRoot();
        $rootIdentity = ResourceIdentity::of('phase6d_customer', '99');

        $resolved = [
            ResolvedResourceGraph::formatKey($rootIdentity->urn(), 'profile') => null,
        ];
        $graph = new ResolvedResourceGraph(
            $root,
            IncludeSet::fromQueryString('profile'),
            $resolved,
        );
        $ctx = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('profile'),
            resolved: $graph,
        );
        $output = $renderer->render($root, $ctx);

        // Resolver said "absent": link-only render despite include.
        self::assertSame('99-profile', $output['profile']['id']);
        self::assertArrayNotHasKey('data', $output['profile']);
    }

    #[Test]
    public function rendering_same_graph_twice_is_deterministic(): void
    {
        $registry = $this->registry();
        $renderer = JsonResourceRenderer::forTesting($registry);
        ['root' => $root, 'graph' => $graph] = $this->buildGraphWithProfile();

        $ctx1 = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('profile'),
            resolved: $graph,
        );
        $ctx2 = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('profile'),
            resolved: $graph,
        );

        self::assertSame($renderer->render($root, $ctx1), $renderer->render($root, $ctx2));
    }
}
