<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\UnloadedRelationException;
use Semitexa\Core\Resource\Exception\UnsupportedRenderProfileException;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\Dotted\DottedAddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\Dotted\DottedCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\Dotted\DottedProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

final class GraphqlResourceRendererTest extends TestCase
{
    private ResourceMetadataRegistry $registry;
    private GraphqlResourceRenderer $renderer;

    protected function setUp(): void
    {
        $extractor      = new ResourceMetadataExtractor();
        $this->registry = ResourceMetadataRegistry::forTesting($extractor);
        $this->registry->register($extractor->extract(AddressResource::class));
        $this->registry->register($extractor->extract(ProfileResource::class));
        $this->registry->register($extractor->extract(CustomerResource::class));
        $this->renderer = GraphqlResourceRenderer::forTesting($this->registry);
    }

    private function ctx(string $rawIncludes = ''): RenderContext
    {
        return new RenderContext(
            profile:  RenderProfile::GraphQL,
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
    public function rejects_non_graphql_profile(): void
    {
        $ctx = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty());
        $this->expectException(UnsupportedRenderProfileException::class);
        $this->renderer->render($this->customer(null, ResourceRefList::to('/x')), $ctx);
    }

    #[Test]
    public function envelope_uses_data_root_with_deterministic_field_name(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );

        self::assertArrayHasKey('data', $output);
        self::assertCount(1, $output['data']);
        self::assertArrayHasKey('customer', $output['data']);
        self::assertSame('123', $output['data']['customer']['id']);
        self::assertSame('customer', $output['data']['customer']['type']);
        self::assertSame('Acme', $output['data']['customer']['name']);
    }

    #[Test]
    public function reference_only_to_one_emits_id_type_href(): void
    {
        $profileRef = ResourceRef::to(
            ResourceIdentity::of('profile', 'p1'),
            '/customers/123/profile',
        );
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/c/123/a')),
            $this->ctx(),
        );

        self::assertSame(
            ['id' => 'p1', 'type' => 'profile', 'href' => '/customers/123/profile'],
            $output['data']['customer']['profile'],
        );
    }

    #[Test]
    public function reference_only_to_one_without_href_emits_id_and_type_only(): void
    {
        $profileRef = ResourceRef::to(ResourceIdentity::of('profile', 'p1'));
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/c/123/a')),
            $this->ctx(),
        );

        self::assertSame(['id' => 'p1', 'type' => 'profile'], $output['data']['customer']['profile']);
    }

    #[Test]
    public function nullable_optional_to_one_renders_null(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/c/123/a')),
            $this->ctx(),
        );
        self::assertNull($output['data']['customer']['profile']);
    }

    #[Test]
    public function embedded_to_one_renders_full_inner_object(): void
    {
        $profile     = new ProfileResource(id: 'p1', bio: 'hi');
        $profileRef  = ResourceRef::embed(ResourceIdentity::of('profile', 'p1'), $profile, '/c/123/profile');
        $output = $this->renderer->render(
            $this->customer($profileRef, ResourceRefList::to('/c/123/a')),
            $this->ctx('profile'),
        );

        self::assertSame('p1', $output['data']['customer']['profile']['id']);
        self::assertSame('profile', $output['data']['customer']['profile']['type']);
        self::assertSame('hi', $output['data']['customer']['profile']['bio']);
        // GraphQL profile must not emit JSON-LD keywords.
        self::assertArrayNotHasKey('@id', $output['data']['customer']['profile']);
        self::assertArrayNotHasKey('@type', $output['data']['customer']['profile']);
        self::assertArrayNotHasKey('@context', $output['data']);
    }

    #[Test]
    public function reference_only_to_many_emits_href_object(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/customers/123/addresses')),
            $this->ctx(),
        );
        self::assertSame(['href' => '/customers/123/addresses'], $output['data']['customer']['addresses']);
    }

    #[Test]
    public function reference_only_to_many_without_href_renders_null(): void
    {
        $list = new ResourceRefList(data: null, href: null);
        $output = $this->renderer->render($this->customer(null, $list), $this->ctx());
        self::assertNull($output['data']['customer']['addresses']);
    }

    #[Test]
    public function embedded_to_many_renders_array_of_objects(): void
    {
        $a1 = new AddressResource(id: 'a1', city: 'Kyiv', line1: 'K1');
        $a2 = new AddressResource(id: 'a2', city: 'Lviv', line1: 'L1');
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([$a1, $a2], '/c/123/a', total: 2)),
            $this->ctx('addresses'),
        );

        self::assertCount(2, $output['data']['customer']['addresses']);
        self::assertSame('a1', $output['data']['customer']['addresses'][0]['id']);
        self::assertSame('address', $output['data']['customer']['addresses'][0]['type']);
        self::assertSame('Kyiv', $output['data']['customer']['addresses'][0]['city']);
    }

    #[Test]
    public function embedded_empty_collection_renders_empty_array(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([], '/c/123/a', total: 0)),
            $this->ctx('addresses'),
        );
        self::assertSame([], $output['data']['customer']['addresses']);
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
            'data' => [
                'customer' => [
                    'id'      => '123',
                    'type'    => 'customer',
                    'name'    => 'Acme',
                    'profile' => [
                        'id'   => '123',
                        'type' => 'profile',
                        'href' => '/customers/123/profile',
                    ],
                    'addresses' => ['href' => '/customers/123/addresses'],
                    'tags'      => [],
                ],
            ],
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
            'data' => [
                'customer' => [
                    'id'      => '123',
                    'type'    => 'customer',
                    'name'    => 'Acme',
                    'profile' => [
                        'id'   => '123',
                        'type' => 'profile',
                        'href' => '/customers/123/profile',
                    ],
                    'addresses' => [
                        ['id' => 'a1', 'type' => 'address', 'city' => 'Kyiv', 'line1' => 'K1'],
                        ['id' => 'a2', 'type' => 'address', 'city' => 'Lviv', 'line1' => 'L1'],
                    ],
                    'tags' => [],
                ],
            ],
        ], $output);
    }

    #[Test]
    public function root_field_name_strips_dotted_namespace(): void
    {
        // The public helper collapses dotted types like
        // `catalog.customer` → `customer`. The Dotted fixtures
        // mirror that naming pattern so this exercise is self-contained
        // inside the package.
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(DottedAddressResource::class));
        $registry->register($extractor->extract(DottedProfileResource::class));
        $registry->register($extractor->extract(DottedCustomerResource::class));

        $renderer = GraphqlResourceRenderer::forTesting($registry);
        $custMeta = $registry->require(DottedCustomerResource::class);
        self::assertSame('customer', $renderer->rootFieldName($custMeta));

        $addrMeta = $registry->require(DottedAddressResource::class);
        self::assertSame('address', $renderer->rootFieldName($addrMeta));
    }
}
