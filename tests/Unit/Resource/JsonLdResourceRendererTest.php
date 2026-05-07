<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\UnloadedRelationException;
use Semitexa\Core\Resource\Exception\UnsupportedRenderProfileException;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
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

final class JsonLdResourceRendererTest extends TestCase
{
    private ResourceMetadataRegistry $registry;
    private JsonLdResourceRenderer $renderer;

    protected function setUp(): void
    {
        $extractor      = new ResourceMetadataExtractor();
        $this->registry = ResourceMetadataRegistry::forTesting($extractor);
        $this->registry->register($extractor->extract(AddressResource::class));
        $this->registry->register($extractor->extract(ProfileResource::class));
        $this->registry->register($extractor->extract(CustomerResource::class));
        $this->renderer = JsonLdResourceRenderer::forTesting($this->registry);
    }

    private function ctx(string $rawIncludes = ''): RenderContext
    {
        return new RenderContext(
            profile:  RenderProfile::JsonLd,
            includes: IncludeSet::fromQueryString($rawIncludes),
        );
    }

    private function customer(?ResourceRef $profile, ResourceRefList $addresses): CustomerResource
    {
        return new CustomerResource(
            id:        '123',
            name:      'Acme',
            profile:   $profile,
            addresses: $addresses,
        );
    }

    #[Test]
    public function rejects_non_jsonld_profile(): void
    {
        $ctx = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty());

        $this->expectException(UnsupportedRenderProfileException::class);
        $this->renderer->render($this->customer(null, ResourceRefList::to('/x')), $ctx);
    }

    #[Test]
    public function root_document_carries_context_id_and_type(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );

        self::assertSame(JsonLdResourceRenderer::DEFAULT_VOCAB, $output['@context']);
        self::assertSame('urn:semitexa:customer:123', $output['@id']);
        self::assertSame('customer', $output['@type']);
        self::assertSame('Acme', $output['name']);
    }

    #[Test]
    public function reference_only_to_one_uses_href_when_present(): void
    {
        $profileRef = ResourceRef::to(
            ResourceIdentity::of('profile', 'p1'),
            '/customers/123/profile',
        );
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );

        self::assertSame(['@id' => '/customers/123/profile', '@type' => 'profile'], $output['profile']);
    }

    #[Test]
    public function reference_only_to_one_falls_back_to_urn_without_href(): void
    {
        $profileRef = ResourceRef::to(ResourceIdentity::of('profile', 'p1'));
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/c/123/a')),
            $this->ctx(),
        );

        self::assertSame(['@id' => 'urn:semitexa:profile:p1', '@type' => 'profile'], $output['profile']);
    }

    #[Test]
    public function nullable_optional_to_one_renders_null(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/c/123/a')),
            $this->ctx(),
        );
        self::assertNull($output['profile']);
    }

    #[Test]
    public function embedded_to_one_renders_full_nested_node(): void
    {
        $profile     = new ProfileResource(id: 'p1', bio: 'hi');
        $profileRef  = ResourceRef::embed(ResourceIdentity::of('profile', 'p1'), $profile, '/c/123/profile');
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/c/123/a')),
            $this->ctx('profile'),
        );

        self::assertSame('/c/123/profile', $output['profile']['@id']);
        self::assertSame('profile', $output['profile']['@type']);
        self::assertSame('hi', $output['profile']['bio']);
    }

    #[Test]
    public function reference_only_to_many_with_href_renders_collection_link(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );
        self::assertSame(['@id' => '/customers/123/addresses'], $output['addresses']);
    }

    #[Test]
    public function reference_only_to_many_without_href_renders_null(): void
    {
        $list = new ResourceRefList(data: null, href: null);
        $output = $this->renderer->render(
            $this->customer(null, $list),
            $this->ctx(),
        );
        self::assertNull($output['addresses']);
    }

    #[Test]
    public function embedded_to_many_renders_array_of_nodes(): void
    {
        $a1 = new AddressResource(id: 'a1', city: 'Kyiv', line1: 'K1');
        $a2 = new AddressResource(id: 'a2', city: 'Lviv', line1: 'L1');
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([$a1, $a2], '/c/123/a', total: 2)),
            $this->ctx('addresses'),
        );

        self::assertCount(2, $output['addresses']);
        self::assertSame('urn:semitexa:address:a1', $output['addresses'][0]['@id']);
        self::assertSame('address', $output['addresses'][0]['@type']);
        self::assertSame('Kyiv', $output['addresses'][0]['city']);
    }

    #[Test]
    public function embedded_empty_collection_renders_empty_array(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([], '/c/123/a', total: 0)),
            $this->ctx('addresses'),
        );
        self::assertSame([], $output['addresses']);
    }

    #[Test]
    public function include_for_unloaded_required_relation_throws(): void
    {
        $profileRef = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/x');
        $this->expectException(UnloadedRelationException::class);
        $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/y')),
            $this->ctx('profile'),
        );
    }

    #[Test]
    public function rendering_is_deterministic(): void
    {
        $profile = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/p');
        $list    = ResourceRefList::to('/a');

        $a = $this->renderer->render($this->customer($profile, $list), $this->ctx());
        $b = $this->renderer->render($this->customer($profile, $list), $this->ctx());
        self::assertSame($a, $b);
    }

    #[Test]
    public function does_not_mutate_input_resource(): void
    {
        $profileRef = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/p');
        $list       = ResourceRefList::to('/a');
        $customer   = $this->customer($profileRef, $list);

        $before = serialize($customer);
        $this->renderer->render($customer, $this->ctx());
        $after = serialize($customer);
        self::assertSame($before, $after);
    }

    #[Test]
    public function does_not_mutate_metadata_registry(): void
    {
        $hashBefore = md5(serialize($this->registry->all()));
        $customer = $this->customer(
            ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/p'),
            ResourceRefList::to('/a'),
        );
        $this->renderer->render($customer, $this->ctx());
        $this->renderer->render($customer, $this->ctx());
        $this->renderer->render($customer, $this->ctx());

        self::assertSame($hashBefore, md5(serialize($this->registry->all())));
    }

    #[Test]
    public function golden_root_envelope_no_includes(): void
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
            '@context' => 'urn:semitexa:vocab/v1',
            '@id'      => 'urn:semitexa:customer:123',
            '@type'    => 'customer',
            'name'     => 'Acme',
            'profile'  => [
                '@id'   => '/customers/123/profile',
                '@type' => 'profile',
            ],
            'addresses' => ['@id' => '/customers/123/addresses'],
            'tags'      => [],
        ], $output);
    }

    #[Test]
    public function golden_root_envelope_with_addresses_included(): void
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
            '@context' => 'urn:semitexa:vocab/v1',
            '@id'      => 'urn:semitexa:customer:123',
            '@type'    => 'customer',
            'name'     => 'Acme',
            'profile'  => [
                '@id'   => '/customers/123/profile',
                '@type' => 'profile',
            ],
            'addresses' => [
                ['@id' => 'urn:semitexa:address:a1', '@type' => 'address', 'city' => 'Kyiv', 'line1' => 'K1'],
                ['@id' => 'urn:semitexa:address:a2', '@type' => 'address', 'city' => 'Lviv', 'line1' => 'L1'],
            ],
            'tags' => [],
        ], $output);
    }
}
