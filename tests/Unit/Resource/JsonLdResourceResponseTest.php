<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\UnknownIncludeException;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
use Semitexa\Core\Resource\JsonLdResourceResponse;
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

final class JsonLdResourceResponseTest extends TestCase
{
    private function wired(): JsonLdResourceResponse
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        $renderer  = JsonLdResourceRenderer::forTesting($registry);
        $validator = IncludeValidator::forTesting($registry);

        $r = new JsonLdResourceResponse();
        return $r->bindServices($renderer, $registry, $validator);
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

    #[Test]
    public function unwired_call_to_with_resource_throws(): void
    {
        $r = new JsonLdResourceResponse();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not wired/');
        $r->withResource(
            $this->customer(),
            new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty()),
        );
    }

    #[Test]
    public function with_resource_sets_jsonld_content_type_and_status(): void
    {
        $resp = $this->wired();
        $resp->withResource(
            $this->customer(),
            new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty()),
        );

        self::assertSame(200, $resp->getStatusCode());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($resp->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('urn:semitexa:vocab/v1', $decoded['@context']);
        self::assertSame('urn:semitexa:customer:123', $decoded['@id']);
        self::assertSame('customer', $decoded['@type']);
        self::assertSame('Acme', $decoded['name']);
    }

    #[Test]
    public function unknown_include_token_yields_400_via_exception(): void
    {
        $resp = $this->wired();
        $this->expectException(UnknownIncludeException::class);
        $resp->withResource(
            $this->customer(),
            new RenderContext(
                profile:  RenderProfile::JsonLd,
                includes: IncludeSet::fromQueryString('orders'),
            ),
        );
    }
}
