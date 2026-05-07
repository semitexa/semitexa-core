<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

/**
 * Phase 2.5: complete relation-state matrix. Each combination is locked
 * down with one explicit test, so OpenAPI generation in Phase 3 can rely
 * on a stable contract.
 */
final class OptionalRelationMatrixTest extends TestCase
{
    private JsonResourceRenderer $renderer;

    protected function setUp(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));
        $this->renderer = JsonResourceRenderer::forTesting($registry);
    }

    private function ctx(string $rawIncludes = ''): RenderContext
    {
        return new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::fromQueryString($rawIncludes));
    }

    private function customer(?ResourceRef $profile, ResourceRefList $addresses): CustomerResource
    {
        return new CustomerResource(
            id:        '1',
            name:      'X',
            profile:   $profile,
            addresses: $addresses,
        );
    }

    #[Test]
    public function optional_to_one_absent_renders_null(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/addr')),
            $this->ctx(),
        );
        self::assertNull($output['profile']);
    }

    #[Test]
    public function optional_to_one_reference_only(): void
    {
        $ref = ResourceRef::to(ResourceIdentity::of('profile', 'p1'), '/p');
        $output = $this->renderer->render(
            $this->customer($ref, ResourceRefList::to('/addr')),
            $this->ctx(),
        );
        self::assertSame(['type' => 'profile', 'id' => 'p1', 'href' => '/p'], $output['profile']);
    }

    #[Test]
    public function optional_to_one_embedded(): void
    {
        $profile = new ProfileResource(id: 'p1', bio: 'hi');
        $ref     = ResourceRef::embed(ResourceIdentity::of('profile', 'p1'), $profile, '/p');
        $output  = $this->renderer->render(
            $this->customer($ref, ResourceRefList::to('/addr')),
            $this->ctx('profile'),
        );

        self::assertSame('profile', $output['profile']['type']);
        self::assertSame('p1', $output['profile']['id']);
        // Phase 6g: ProfileResource gained an optional resolver-backed
        // `preferences` relation. Without an overlay or eager data, it
        // renders as `null`. The assertion stays scoped to the keys
        // this test cares about (`id`, `bio`, `preferences`).
        self::assertSame(
            ['id' => 'p1', 'bio' => 'hi', 'preferences' => null],
            $output['profile']['data'],
        );
    }

    #[Test]
    public function required_to_many_reference_only_with_href(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::to('/customers/1/addresses')),
            $this->ctx(),
        );
        self::assertSame(['href' => '/customers/1/addresses'], $output['addresses']);
    }

    #[Test]
    public function required_to_many_embedded(): void
    {
        $a = new AddressResource(id: 'a1', city: 'K', line1: 'L1');
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([$a], '/customers/1/addresses', total: 1)),
            $this->ctx('addresses'),
        );
        self::assertSame(1, $output['addresses']['total']);
        self::assertCount(1, $output['addresses']['data']);
    }

    #[Test]
    public function required_to_many_embedded_empty(): void
    {
        $output = $this->renderer->render(
            $this->customer(null, ResourceRefList::embed([], '/customers/1/addresses', total: 0)),
            $this->ctx('addresses'),
        );
        self::assertSame([], $output['addresses']['data']);
        self::assertSame(0, $output['addresses']['total']);
    }

    #[Test]
    public function reference_only_to_many_requires_non_empty_href(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires a non-empty href/');
        ResourceRefList::to('');
    }

    #[Test]
    public function constructed_directly_with_no_href_no_data_renders_as_null(): void
    {
        // Bypassing the factory — defensive renderer behavior.
        $list = new ResourceRefList(data: null, href: null);
        $output = $this->renderer->render(
            $this->customer(null, $list),
            $this->ctx(),
        );
        self::assertNull($output['addresses']);
    }

    #[Test]
    public function embedded_to_many_without_href_still_renders_data(): void
    {
        // Handler chose to embed without a canonical URL — allowed.
        $a = new AddressResource(id: 'a1', city: 'K', line1: 'L1');
        $list = new ResourceRefList(data: [$a], href: null);
        $output = $this->renderer->render(
            $this->customer(null, $list),
            $this->ctx(),
        );
        self::assertArrayNotHasKey('href', $output['addresses']);
        self::assertCount(1, $output['addresses']['data']);
        self::assertSame(1, $output['addresses']['total']);
    }
}
