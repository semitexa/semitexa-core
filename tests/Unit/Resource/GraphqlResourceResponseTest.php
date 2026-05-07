<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\UnknownIncludeException;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\GraphqlResourceResponse;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
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

final class GraphqlResourceResponseTest extends TestCase
{
    private function wired(): GraphqlResourceResponse
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        $renderer  = GraphqlResourceRenderer::forTesting($registry);
        $validator = IncludeValidator::forTesting($registry);

        $r = new GraphqlResourceResponse();
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
        $r = new GraphqlResourceResponse();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/not wired/');
        $r->withResource(
            $this->customer(),
            new RenderContext(profile: RenderProfile::GraphQL, includes: IncludeSet::empty()),
        );
    }

    #[Test]
    public function with_resource_sets_graphql_content_type_and_status(): void
    {
        $resp = $this->wired();
        $resp->withResource(
            $this->customer(),
            new RenderContext(profile: RenderProfile::GraphQL, includes: IncludeSet::empty()),
        );

        self::assertSame(200, $resp->getStatusCode());

        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode($resp->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('customer', $decoded['data']);
        self::assertSame('123', $decoded['data']['customer']['id']);
        self::assertSame('customer', $decoded['data']['customer']['type']);
    }

    #[Test]
    public function unknown_include_token_yields_400_via_exception(): void
    {
        $resp = $this->wired();
        $this->expectException(UnknownIncludeException::class);
        $resp->withResource(
            $this->customer(),
            new RenderContext(
                profile:  RenderProfile::GraphQL,
                includes: IncludeSet::fromQueryString('orders'),
            ),
        );
    }
}
