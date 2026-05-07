<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\GraphqlResourceRenderer;
use Semitexa\Core\Resource\GraphqlResourceResponse;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\JsonLdResourceRenderer;
use Semitexa\Core\Resource\JsonLdResourceResponse;
use Semitexa\Core\Resource\JsonResourceRenderer;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Pagination\CollectionPage;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\RenderProfile;
use Semitexa\Core\Resource\ResourceExpansionPipeline;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6i: collection envelopes carry pagination metadata; the
 * resolver pipeline only sees the paged slice.
 *
 * The handler-level slice happens *before* `expandMany()` is called,
 * so when the test passes a 5-customer source list with
 * `page=1&perPage=2`, the recording profile resolver must observe
 * exactly 2 parent identities.
 */
final class Phase6iCollectionPaginationTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingProfileResolver::reset();
        RecordingAddressesResolver::reset();
        RecordingPreferencesResolver::reset();
    }

    private function registry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerWithBothResolvedResource::class));
        return $registry;
    }

    private function customer(string $id): CustomerWithBothResolvedResource
    {
        return new CustomerWithBothResolvedResource(
            id: $id,
            profile: ResourceRef::to(
                ResourceIdentity::of('profile', $id . '-profile'),
                "/x/{$id}/profile",
            ),
            addresses: ResourceRefList::to("/x/{$id}/addresses"),
        );
    }

    /** @return list<CustomerWithBothResolvedResource> */
    private function fiveCustomers(): array
    {
        return [
            $this->customer('1'),
            $this->customer('2'),
            $this->customer('3'),
            $this->customer('4'),
            $this->customer('5'),
        ];
    }

    /** @param array<class-string, object> $bindings */
    private function container(array $bindings): ContainerInterface
    {
        return new class ($bindings) implements ContainerInterface {
            /** @param array<class-string, object> $b */
            public function __construct(private array $b) {}

            public function get(string $id): object
            {
                if (!array_key_exists($id, $this->b)) {
                    throw new class extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
                }
                return $this->b[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->b);
            }
        };
    }

    private function pipeline(ResourceMetadataRegistry $registry): ResourceExpansionPipeline
    {
        return ResourceExpansionPipeline::forTesting(
            $registry,
            $this->container([
                RecordingProfileResolver::class     => new RecordingProfileResolver(),
                RecordingAddressesResolver::class   => new RecordingAddressesResolver(),
                RecordingPreferencesResolver::class => new RecordingPreferencesResolver(),
            ]),
        );
    }

    private function jsonResponse(ResourceMetadataRegistry $registry): JsonResourceResponse
    {
        return (new JsonResourceResponse())->bindServices(
            JsonResourceRenderer::forTesting($registry),
            $registry,
            IncludeValidator::forTesting($registry),
            $this->pipeline($registry),
        );
    }

    private function jsonLdResponse(ResourceMetadataRegistry $registry): JsonLdResourceResponse
    {
        return (new JsonLdResourceResponse())->bindServices(
            JsonLdResourceRenderer::forTesting($registry),
            $registry,
            IncludeValidator::forTesting($registry),
            $this->pipeline($registry),
        );
    }

    private function graphqlResponse(ResourceMetadataRegistry $registry): GraphqlResourceResponse
    {
        return (new GraphqlResourceResponse())->bindServices(
            GraphqlResourceRenderer::forTesting($registry),
            $registry,
            IncludeValidator::forTesting($registry),
            $this->pipeline($registry),
        );
    }

    /**
     * Mirror the handler's slice-then-expand flow.
     *
     * @return array{body: array<string, mixed>, page: CollectionPage}
     */
    private function renderPagedJson(string $rawPage, string $rawPerPage, IncludeSet $includes): array
    {
        $registry = $this->registry();
        $response = $this->jsonResponse($registry);
        $request  = CollectionPageRequest::fromQueryParams($rawPage, $rawPerPage);
        $all      = $this->fiveCustomers();
        $page     = CollectionPage::compute($request, count($all));
        $sliced   = $request->slice($all);

        $context = new RenderContext(profile: RenderProfile::Json, includes: $includes);
        $response->withResources($sliced, $context, CustomerWithBothResolvedResource::class, $page);

        return [
            'body' => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
            'page' => $page,
        ];
    }

    // ----- JSON envelope ---------------------------------------------

    #[Test]
    public function json_collection_includes_pagination_meta(): void
    {
        $r = $this->renderPagedJson('1', '10', IncludeSet::empty());
        $body = $r['body'];

        self::assertSame(['data', 'meta'], array_keys($body));
        self::assertSame(
            [
                'page'        => 1,
                'perPage'     => 10,
                'total'       => 5,
                'pageCount'   => 1,
                'hasNext'     => false,
                'hasPrevious' => false,
            ],
            $body['meta']['pagination'],
        );
        self::assertCount(5, $body['data']);
    }

    #[Test]
    public function json_page_one_per_page_one_returns_first_item_with_meta(): void
    {
        $r = $this->renderPagedJson('1', '1', IncludeSet::empty());
        $body = $r['body'];

        self::assertCount(1, $body['data']);
        self::assertSame('1', $body['data'][0]['id']);
        self::assertSame(5, $body['meta']['pagination']['total']);
        self::assertSame(5, $body['meta']['pagination']['pageCount']);
        self::assertTrue($body['meta']['pagination']['hasNext']);
        self::assertFalse($body['meta']['pagination']['hasPrevious']);
    }

    #[Test]
    public function json_page_two_per_page_one_returns_second_item(): void
    {
        $r = $this->renderPagedJson('2', '1', IncludeSet::empty());
        $body = $r['body'];

        self::assertCount(1, $body['data']);
        self::assertSame('2', $body['data'][0]['id']);
        self::assertSame(2, $body['meta']['pagination']['page']);
        self::assertTrue($body['meta']['pagination']['hasNext']);
        self::assertTrue($body['meta']['pagination']['hasPrevious']);
    }

    #[Test]
    public function json_page_beyond_last_returns_empty_data_with_total_preserved(): void
    {
        $r = $this->renderPagedJson('999', '10', IncludeSet::empty());
        $body = $r['body'];

        self::assertSame([], $body['data']);
        self::assertSame(5, $body['meta']['pagination']['total']);
        self::assertSame(999, $body['meta']['pagination']['page']);
        self::assertFalse($body['meta']['pagination']['hasNext']);
        self::assertTrue($body['meta']['pagination']['hasPrevious']);
    }

    // ----- JSON-LD envelope ------------------------------------------

    #[Test]
    public function jsonld_collection_includes_pagination_meta(): void
    {
        $registry = $this->registry();
        $response = $this->jsonLdResponse($registry);
        $request  = CollectionPageRequest::fromQueryParams('2', '2');
        $all      = $this->fiveCustomers();
        $page     = CollectionPage::compute($request, count($all));
        $sliced   = $request->slice($all);

        $context = new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty());
        $response->withResources($sliced, $context, CustomerWithBothResolvedResource::class, $page);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(JsonLdResourceRenderer::DEFAULT_VOCAB, $body['@context']);
        self::assertCount(2, $body['@graph']);
        // JSON-LD encodes the resource id into `@id` as a urn (Phase 4
        // shape); a separate `id` field is not emitted on the node.
        self::assertSame('phase6e_customer', $body['@graph'][0]['@type']);
        self::assertSame('urn:semitexa:phase6e_customer:3', $body['@graph'][0]['@id']);
        self::assertSame('urn:semitexa:phase6e_customer:4', $body['@graph'][1]['@id']);
        self::assertSame(
            [
                'page'        => 2,
                'perPage'     => 2,
                'total'       => 5,
                'pageCount'   => 3,
                'hasNext'     => true,
                'hasPrevious' => true,
            ],
            $body['meta']['pagination'],
        );
    }

    // ----- GraphQL envelope ------------------------------------------

    #[Test]
    public function graphql_collection_includes_pagination_meta(): void
    {
        $registry = $this->registry();
        $response = $this->graphqlResponse($registry);
        $request  = CollectionPageRequest::fromQueryParams('1', '3');
        $all      = $this->fiveCustomers();
        $page     = CollectionPage::compute($request, count($all));
        $sliced   = $request->slice($all);

        $context = new RenderContext(profile: RenderProfile::GraphQL, includes: IncludeSet::empty());
        $response->withResources('customers', $sliced, $context, CustomerWithBothResolvedResource::class, $page);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(3, $body['data']['customers']);
        self::assertSame(['data', 'meta'], array_keys($body));
        self::assertSame(1, $body['meta']['pagination']['page']);
        self::assertSame(3, $body['meta']['pagination']['perPage']);
        self::assertSame(5, $body['meta']['pagination']['total']);
        self::assertSame(2, $body['meta']['pagination']['pageCount']);
        self::assertTrue($body['meta']['pagination']['hasNext']);
        self::assertFalse($body['meta']['pagination']['hasPrevious']);
    }

    // ----- Resolver batching shrinks to paged slice -------------------

    #[Test]
    public function resolver_only_sees_paged_parents_when_pagination_narrows_the_slice(): void
    {
        // 5 customers in source; page=1&perPage=1 should pass exactly
        // one identity to the profile resolver.
        $this->renderPagedJson('1', '1', IncludeSet::fromQueryString('profile'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingProfileResolver::$calls[0]['parents']);

        RecordingProfileResolver::reset();
        $this->renderPagedJson('1', '2', IncludeSet::fromQueryString('profile'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(2, RecordingProfileResolver::$calls[0]['parents']);
    }

    #[Test]
    public function nested_expansion_only_resolves_paged_parents_profiles(): void
    {
        $this->renderPagedJson('1', '2', IncludeSet::fromQueryString('profile.preferences'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(2, RecordingProfileResolver::$calls[0]['parents']);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertCount(2, RecordingPreferencesResolver::$calls[0]['parents']);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function no_include_calls_no_resolver_even_with_pagination(): void
    {
        $this->renderPagedJson('1', '1', IncludeSet::empty());
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function repeated_paged_renders_do_not_leak_overlay_state(): void
    {
        $first = $this->renderPagedJson('1', '2', IncludeSet::fromQueryString('profile'));
        // Advancing to page 2 must not see profiles for page 1's
        // customers: the second render starts from a fresh
        // ResolvedResourceGraph and a new resolver call.
        RecordingProfileResolver::reset();
        $second = $this->renderPagedJson('2', '2', IncludeSet::fromQueryString('profile'));

        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertSame(['3', '4'], array_map(
            static fn (ResourceIdentity $i) => substr($i->id, 0, 1),
            RecordingProfileResolver::$calls[0]['parents'],
        ));

        self::assertSame('1', $first['body']['data'][0]['id']);
        self::assertSame('3', $second['body']['data'][0]['id']);
    }

    // ----- Backwards compatibility -----------------------------------

    #[Test]
    public function with_resources_without_page_does_not_emit_meta(): void
    {
        $registry = $this->registry();
        $response = $this->jsonResponse($registry);
        $context  = new RenderContext(profile: RenderProfile::Json, includes: IncludeSet::empty());
        $response->withResources(
            $this->fiveCustomers(),
            $context,
            CustomerWithBothResolvedResource::class,
        );

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['data'], array_keys($body));
    }

    #[Test]
    public function single_resource_response_remains_unchanged_under_phase_6i(): void
    {
        $registry = $this->registry();
        $renderer = JsonResourceRenderer::forTesting($registry);
        $response = (new JsonResourceResponse())->bindServices(
            $renderer,
            $registry,
            IncludeValidator::forTesting($registry),
            $this->pipeline($registry),
        );
        $context = new RenderContext(
            profile:  RenderProfile::Json,
            includes: IncludeSet::fromQueryString('profile'),
        );
        $response->withResource($this->customer('7'), $context);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['data'], array_keys($body));
        self::assertArrayNotHasKey('meta', $body);
        self::assertSame('7-profile', $body['data']['profile']['data']['id']);
    }
}
