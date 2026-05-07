<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourcePageInfo;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;

final class ResourceRefEnvelopeTest extends TestCase
{
    #[Test]
    public function identity_rejects_empty_type_or_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResourceIdentity('', '1');
    }

    #[Test]
    public function identity_urn_is_stable_and_typed(): void
    {
        $a = ResourceIdentity::of('customer', '123');
        $b = ResourceIdentity::of('customer', '123');
        $c = ResourceIdentity::of('customer', '124');

        self::assertSame('urn:semitexa:customer:123', $a->urn());
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function ref_to_is_unloaded_and_carries_optional_href(): void
    {
        $ref = ResourceRef::to(ResourceIdentity::of('profile', '7'), '/customers/7/profile');
        self::assertFalse($ref->isLoaded());
        self::assertNull($ref->data);
        self::assertSame('/customers/7/profile', $ref->href);
    }

    #[Test]
    public function ref_embed_is_loaded_and_carries_data(): void
    {
        $address = new AddressResource(id: 'a1', city: 'Kyiv', line1: 'Khreshchatyk');
        $ref = ResourceRef::embed(ResourceIdentity::of('address', 'a1'), $address);

        self::assertTrue($ref->isLoaded());
        self::assertSame($address, $ref->data);
    }

    #[Test]
    public function reflist_to_is_unloaded_with_optional_href(): void
    {
        $list = ResourceRefList::to('/customers/1/addresses');

        self::assertFalse($list->isLoaded());
        self::assertNull($list->data);
        self::assertSame('/customers/1/addresses', $list->href);
    }

    #[Test]
    public function reflist_embed_distinguishes_empty_loaded_from_unloaded(): void
    {
        $empty = ResourceRefList::embed([], '/customers/1/addresses', total: 0);
        self::assertTrue($empty->isLoaded());
        self::assertSame([], $empty->data);
        self::assertSame(0, $empty->total);

        $unloaded = ResourceRefList::to('/customers/1/addresses');
        self::assertFalse($unloaded->isLoaded());
    }

    #[Test]
    public function page_info_validates_bounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResourcePageInfo(page: 0, perPage: 10);
    }

    #[Test]
    public function include_set_normalizes_dedupes_and_sorts(): void
    {
        $set = IncludeSet::fromQueryString('Profile,addresses,profile');
        self::assertSame(['addresses', 'profile'], $set->tokens);
        self::assertTrue($set->has('PROFILE'));
        self::assertFalse($set->has('orders'));
    }

    #[Test]
    public function include_set_supports_dot_notation_nesting(): void
    {
        $set    = IncludeSet::fromQueryString('addresses,addresses.country,profile');
        $nested = $set->nested('addresses');
        self::assertSame(['country'], $nested->tokens);
        self::assertTrue($nested->has('country'));
    }

    #[Test]
    public function render_context_tracks_visited_and_depth(): void
    {
        $ctx = new RenderContext(
            profile: RenderProfile::Json,
            includes: IncludeSet::empty(),
            baseUrl: 'https://api.example.com',
            maxDepth: 3,
        );

        $a = ResourceIdentity::of('customer', '1');

        self::assertSame(0, $ctx->depth());
        self::assertFalse($ctx->isVisited($a));

        $ctx->enter($a);
        self::assertSame(1, $ctx->depth());
        self::assertTrue($ctx->isVisited($a));

        $ctx->leave($a);
        self::assertSame(0, $ctx->depth());
        self::assertFalse($ctx->isVisited($a));
    }
}
