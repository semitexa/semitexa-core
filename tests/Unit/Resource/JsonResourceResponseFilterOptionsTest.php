<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Pagination\CollectionPage;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * One Way Phase 2: server-fed `meta.filterOptions` on the collection
 * envelope — present only when the handler supplies it, so every
 * existing caller stays byte-identical.
 */
final class JsonResourceResponseFilterOptionsTest extends TestCase
{
    private function wired(): JsonResourceResponse
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        return (new JsonResourceResponse())->bindServices(
            JsonResourceRenderer::forTesting($registry),
            $registry,
            IncludeValidator::forTesting($registry),
        );
    }

    private function customer(): CustomerResource
    {
        return new CustomerResource(
            id:        '123',
            name:      'Acme',
            profile:   ResourceRef::to(ResourceIdentity::of('profile', '123'), '/customers/123/profile'),
            addresses: ResourceRefList::to('/customers/123/addresses'),
        );
    }

    private function context(): RenderContext
    {
        return new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty());
    }

    #[Test]
    public function filter_options_land_in_meta_alongside_pagination(): void
    {
        $resp = $this->wired();
        $resp->withResources(
            [$this->customer()],
            $this->context(),
            CustomerResource::class,
            CollectionPage::compute(CollectionPageRequest::fromQueryParams(null, null), 1),
            null,
            ['status' => [['value' => 'a', 'label' => 'Active']]],
        );

        $decoded = json_decode($resp->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['status' => [['value' => 'a', 'label' => 'Active']]],
            $decoded['meta']['filterOptions'],
        );
        self::assertArrayHasKey('pagination', $decoded['meta']);
    }

    #[Test]
    public function filter_options_alone_still_create_the_meta_block(): void
    {
        $resp = $this->wired();
        $resp->withResources(
            [$this->customer()],
            $this->context(),
            CustomerResource::class,
            null,
            null,
            ['status' => [['value' => 'a', 'label' => 'Active']]],
        );

        $decoded = json_decode($resp->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('filterOptions', $decoded['meta']);
        self::assertArrayNotHasKey('pagination', $decoded['meta']);
    }

    #[Test]
    public function omitted_filter_options_keep_the_envelope_byte_identical(): void
    {
        $resp = $this->wired();
        $resp->withResources([$this->customer()], $this->context(), CustomerResource::class);

        $decoded = json_decode($resp->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('meta', $decoded);
    }
}
