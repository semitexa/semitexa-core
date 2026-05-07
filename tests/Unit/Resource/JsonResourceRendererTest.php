<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\UnloadedRelationException;
use Semitexa\Core\Resource\Exception\UnsupportedRenderProfileException;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

final class JsonResourceRendererTest extends TestCase
{
    private ResourceMetadataRegistry $registry;
    private JsonResourceRenderer $renderer;

    protected function setUp(): void
    {
        $extractor      = new ResourceMetadataExtractor();
        $this->registry = ResourceMetadataRegistry::forTesting($extractor);
        $this->registry->register($extractor->extract(AddressResource::class));
        $this->registry->register($extractor->extract(ProfileResource::class));
        $this->registry->register($extractor->extract(CustomerResource::class));
        $this->renderer = JsonResourceRenderer::forTesting($this->registry);
    }

    private function ctx(string $rawIncludes = ''): RenderContext
    {
        return new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString($rawIncludes),
        );
    }

    private function customer(
        ?ResourceRef $profile,
        ResourceRefList $addresses,
    ): CustomerResource {
        return new CustomerResource(
            id:       '123',
            name:     'Acme',
            profile:  $profile,
            addresses: $addresses,
        );
    }

    #[Test]
    public function rejects_non_json_profile(): void
    {
        $ctx = new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty());

        $this->expectException(UnsupportedRenderProfileException::class);
        $this->renderer->render($this->customer(null, ResourceRefList::to('/x')), $ctx);
    }

    #[Test]
    public function reference_only_to_one_envelope_carries_type_id_href(): void
    {
        $profileRef = ResourceRef::to(
            ResourceIdentity::of('profile', 'p1'),
            '/customers/123/profile',
        );
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );

        self::assertSame([
            'type' => 'profile',
            'id'   => 'p1',
            'href' => '/customers/123/profile',
        ], $output['profile']);
    }

    #[Test]
    public function reference_only_to_many_envelope_carries_only_href(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );

        self::assertSame(['href' => '/customers/123/addresses'], $output['addresses']);
    }

    #[Test]
    public function embedded_to_many_renders_data_and_total(): void
    {
        $a1 = new AddressResource(id: 'a1', city: 'Kyiv', line1: 'K1');
        $a2 = new AddressResource(id: 'a2', city: 'Lviv', line1: 'L1');

        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([$a1, $a2], '/customers/123/addresses', total: 2)),
            $this->ctx('addresses'),
        );

        $envelope = $output['addresses'];
        self::assertSame('/customers/123/addresses', $envelope['href']);
        self::assertSame(2, $envelope['total']);
        self::assertCount(2, $envelope['data']);
        self::assertSame('a1', $envelope['data'][0]['id']);
    }

    #[Test]
    public function embedded_empty_collection_serializes_as_empty_array(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([], '/customers/123/addresses', total: 0)),
            $this->ctx('addresses'),
        );

        self::assertSame([], $output['addresses']['data']);
        self::assertSame(0, $output['addresses']['total']);
    }

    #[Test]
    public function nullable_to_one_renders_null_when_relation_is_null(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/x')),
            $this->ctx(),
        );

        self::assertNull($output['profile']);
    }

    #[Test]
    public function include_for_unloaded_required_relation_throws(): void
    {
        // Profile is requested via include=profile, but the ref carries no data.
        $profileRef = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/x');

        $this->expectException(UnloadedRelationException::class);
        $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/y')),
            $this->ctx('profile'),
        );
    }

    #[Test]
    public function rendering_is_deterministic_for_same_input(): void
    {
        $profile = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/p');
        $list    = ResourceRefList::to('/a');

        $a = $this->renderer->render($this->customer($profile, $list), $this->ctx());
        $b = $this->renderer->render($this->customer($profile, $list), $this->ctx());

        self::assertSame($a, $b);
    }

    #[Test]
    public function rendering_two_calls_with_different_base_urls_does_not_leak(): void
    {
        // Render the same DTO with two contexts that carry different base URLs.
        // The renderer ignores baseUrl entirely (it lives on RenderContext, not in
        // the DTO), so both outputs should be identical — proving no leakage.
        $profile = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/p');
        $list    = ResourceRefList::to('/a');

        $ctx1 = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty(), baseUrl: 'https://a.example.com');
        $ctx2 = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty(), baseUrl: 'https://b.example.com');

        $a = $this->renderer->render($this->customer($profile, $list), $ctx1);
        $b = $this->renderer->render($this->customer($profile, $list), $ctx2);

        self::assertSame($a, $b);
        self::assertSame('/p', $a['profile']['href']);
    }

    #[Test]
    public function does_not_mutate_input_resource(): void
    {
        $profileRef = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/p');
        $list       = ResourceRefList::to('/a');
        $customer   = $this->customer($profileRef, $list);

        $before = serialize($customer);
        $this->renderer->render($customer, $this->ctx());
        $after  = serialize($customer);

        self::assertSame($before, $after);
    }

    #[Test]
    public function golden_envelope_for_no_includes(): void
    {
        $profileRef = ResourceRef::to(
            ResourceIdentity::of('profile', '123'),
            '/customers/123/profile',
        );
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );

        self::assertSame([
            'id'        => '123',
            'name'      => 'Acme',
            'profile'   => [
                'type' => 'profile',
                'id'   => '123',
                'href' => '/customers/123/profile',
            ],
            'addresses' => ['href' => '/customers/123/addresses'],
            'tags'      => [],
        ], $output);
    }

    #[Test]
    public function golden_envelope_with_addresses_included(): void
    {
        $profileRef = ResourceRef::to(
            ResourceIdentity::of('profile', '123'),
            '/customers/123/profile',
        );
        $a1 = new AddressResource(id: 'a1', city: 'Kyiv', line1: 'K1');
        $a2 = new AddressResource(id: 'a2', city: 'Lviv', line1: 'L1');

        $output = $this->renderer->render(
            $this->customer(
                $profileRef,
                ResourceRefList::embed([$a1, $a2], '/customers/123/addresses', total: 2),
            ),
            $this->ctx('addresses'),
        );

        self::assertSame([
            'id'        => '123',
            'name'      => 'Acme',
            'profile'   => [
                'type' => 'profile',
                'id'   => '123',
                'href' => '/customers/123/profile',
            ],
            'addresses' => [
                'href'  => '/customers/123/addresses',
                'data'  => [
                    ['id' => 'a1', 'city' => 'Kyiv',  'line1' => 'K1'],
                    ['id' => 'a2', 'city' => 'Lviv', 'line1' => 'L1'],
                ],
                'total' => 2,
            ],
            'tags'      => [],
        ], $output);
    }
}
