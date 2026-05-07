<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Exception\InvalidFilterException;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
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
use Semitexa\Core\Resource\Sort\CollectionSortRequest;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6k: filter happens before sort and pagination, and the
 * resolver pipeline only sees the visible filtered+sorted+paged
 * slice. Tests mirror a typical list handler flow on synthetic
 * input.
 *
 * Pipeline order:
 *   catalog → filter → sort → paginate → slice → expandMany → render
 */
final class Phase6kCollectionFilterIntegrationTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const FILTER_ALLOW = [
        'id'   => ['eq', 'in'],
        'name' => ['eq', 'contains'],
    ];

    private const SORT_ALLOW = ['id', 'name'];

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

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        // Out-of-id-order; names overlap to exercise eq vs contains.
        return [
            ['id' => '3', 'name' => 'Cobalt'],
            ['id' => '1', 'name' => 'Acme'],
            ['id' => '4', 'name' => 'Beta'],
            ['id' => '2', 'name' => 'Delta'],
            ['id' => '5', 'name' => 'AcmeCorp'],
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
     * Mirror handler flow: filter → sort → page → slice → build → render.
     *
     * @return array{body: array<string, mixed>, page: CollectionPage}
     */
    private function renderJson(
        ?string $rawFilter,
        ?string $rawSort,
        string $rawPage,
        string $rawPerPage,
        IncludeSet $includes,
    ): array {
        $registry = $this->registry();
        $response = $this->jsonResponse($registry);

        $filter = CollectionFilterRequest::fromQueryParam($rawFilter, self::FILTER_ALLOW);
        $sort   = CollectionSortRequest::fromQueryParam($rawSort, self::SORT_ALLOW);

        $extractor = static fn (array $r, string $f) => $r[$f] ?? null;

        $rows     = $this->rows();
        $filtered = $filter->apply($rows, $extractor);
        $sorted   = $sort->apply($filtered, $extractor);

        $req    = CollectionPageRequest::fromQueryParams($rawPage, $rawPerPage);
        $page   = CollectionPage::compute($req, count($sorted));
        $sliced = $req->slice($sorted);

        $customers = [];
        foreach ($sliced as $row) {
            $customers[] = $this->customer($row['id']);
        }

        $context = new RenderContext(profile: RenderProfile::Json, includes: $includes);
        $response->withResources($customers, $context, CustomerWithBothResolvedResource::class, $page);

        return [
            'body' => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
            'page' => $page,
        ];
    }

    // ----- Default behaviour preserved -------------------------------

    #[Test]
    public function omitted_filter_preserves_phase_6j_output_byte_identical(): void
    {
        $r = $this->renderJson(null, null, '1', '10', IncludeSet::empty());
        self::assertSame(['3', '1', '4', '2', '5'], array_column($r['body']['data'], 'id'));
        self::assertSame(5, $r['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function empty_filter_string_treated_as_omitted(): void
    {
        $r = $this->renderJson('', null, '1', '10', IncludeSet::empty());
        self::assertSame(['3', '1', '4', '2', '5'], array_column($r['body']['data'], 'id'));
    }

    // ----- Single-filter cases ---------------------------------------

    #[Test]
    public function filter_id_eq_returns_one_customer(): void
    {
        $r = $this->renderJson('id:eq:1', null, '1', '10', IncludeSet::empty());
        self::assertCount(1, $r['body']['data']);
        self::assertSame('1', $r['body']['data'][0]['id']);
        self::assertSame(1, $r['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function filter_id_in_returns_matching_customers_in_input_order(): void
    {
        $r = $this->renderJson('id:in:1,4', null, '1', '10', IncludeSet::empty());
        self::assertSame(['1', '4'], array_column($r['body']['data'], 'id'));
        self::assertSame(2, $r['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function filter_name_eq_is_case_sensitive(): void
    {
        $hit  = $this->renderJson('name:eq:Acme', null, '1', '10', IncludeSet::empty());
        $miss = $this->renderJson('name:eq:acme', null, '1', '10', IncludeSet::empty());

        self::assertSame(['1'], array_column($hit['body']['data'], 'id'));
        self::assertSame([],    $miss['body']['data']);
        self::assertSame(0, $miss['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function filter_name_contains_is_case_insensitive(): void
    {
        $r = $this->renderJson('name:contains:acme', null, '1', '10', IncludeSet::empty());
        self::assertSame(['1', '5'], array_column($r['body']['data'], 'id'));
    }

    #[Test]
    public function filter_matching_zero_rows_returns_empty_data_with_total_zero(): void
    {
        $r = $this->renderJson('name:contains:zzz', null, '1', '10', IncludeSet::empty());
        self::assertSame([], $r['body']['data']);
        self::assertSame(0, $r['body']['meta']['pagination']['total']);
        self::assertFalse($r['body']['meta']['pagination']['hasNext']);
        self::assertFalse($r['body']['meta']['pagination']['hasPrevious']);
    }

    // ----- Filter + sort + pagination ordering -----------------------

    #[Test]
    public function filter_applies_before_sort(): void
    {
        // Filter to {1, 4, 5}; sort by name desc.
        // Names involved: Acme (1), Beta (4), AcmeCorp (5).
        // Lexicographic asc: Acme < AcmeCorp < Beta
        // Lexicographic desc: Beta > AcmeCorp > Acme → ids 4, 5, 1.
        $r = $this->renderJson('id:in:1,4,5', '-name', '1', '10', IncludeSet::empty());
        self::assertSame(['4', '5', '1'], array_column($r['body']['data'], 'id'));
        self::assertSame(3, $r['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function filter_applies_before_pagination_total_reflects_filtered_set(): void
    {
        // Filter to {1, 5}; with perPage=1 the total stays at 2 and
        // the second page returns AcmeCorp.
        $page1 = $this->renderJson('name:contains:acme', null, '1', '1', IncludeSet::empty());
        $page2 = $this->renderJson('name:contains:acme', null, '2', '1', IncludeSet::empty());

        self::assertSame(['1'], array_column($page1['body']['data'], 'id'));
        self::assertSame(['5'], array_column($page2['body']['data'], 'id'));
        self::assertSame(2, $page1['body']['meta']['pagination']['total']);
        self::assertSame(2, $page2['body']['meta']['pagination']['total']);
        self::assertTrue($page1['body']['meta']['pagination']['hasNext']);
        self::assertFalse($page2['body']['meta']['pagination']['hasNext']);
    }

    #[Test]
    public function filter_then_sort_then_paginate_picks_first_filtered_sorted_item(): void
    {
        // Filter id IN {1,2,3,4,5} ∩ name contains "a" =
        //   "Acme" (1), "AcmeCorp" (5), "Cobalt" (3, contains 'a' — yes!),
        //   "Beta" (4, contains 'a'), "Delta" (2, contains 'a').
        // All five names contain 'a' (case-insensitive). Sort by name
        // ascending → Acme, AcmeCorp, Beta, Cobalt, Delta. Page 1
        // perPage 1 → Acme(1).
        $r = $this->renderJson('name:contains:a', 'name', '1', '1', IncludeSet::empty());
        self::assertSame(['1'], array_column($r['body']['data'], 'id'));
        self::assertSame(5, $r['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function filter_with_descending_sort_and_pagination_picks_filtered_top(): void
    {
        // Filter id IN {1,2,3}; sort -id; page 1 perPage 1 → id=3.
        $r = $this->renderJson('id:in:1,2,3', '-id', '1', '1', IncludeSet::empty());
        self::assertSame(['3'], array_column($r['body']['data'], 'id'));
        self::assertSame(3, $r['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function multi_term_filter_uses_and_semantics(): void
    {
        // id IN {1,2,3,4,5} ∧ name contains "acme" → {1, 5}.
        $r = $this->renderJson('id:in:1,2,3,4,5;name:contains:acme', null, '1', '10', IncludeSet::empty());
        self::assertSame(['1', '5'], array_column($r['body']['data'], 'id'));
    }

    // ----- Resolver batching shrinks to filtered+paged parents ----

    #[Test]
    public function include_profile_with_filter_passes_only_filtered_parents(): void
    {
        $this->renderJson('id:in:1,4', null, '1', '10', IncludeSet::fromQueryString('profile'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        $ids = array_map(static fn ($i) => $i->id, RecordingProfileResolver::$calls[0]['parents']);
        self::assertSame(['1', '4'], $ids);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function include_addresses_with_filter_and_sort_passes_only_filtered_sorted_parents(): void
    {
        // Filter to {1, 4, 5}; sort by -id → 5, 4, 1; page perPage 2 → 5, 4.
        $this->renderJson('id:in:1,4,5', '-id', '1', '2', IncludeSet::fromQueryString('addresses'));
        self::assertCount(1, RecordingAddressesResolver::$calls);
        $ids = array_map(static fn ($i) => $i->id, RecordingAddressesResolver::$calls[0]['parents']);
        self::assertSame(['5', '4'], $ids);
        self::assertSame([], RecordingProfileResolver::$calls);
    }

    #[Test]
    public function include_profile_preferences_with_filter_runs_two_buckets_only_for_filtered_parents(): void
    {
        $this->renderJson('name:contains:acme', null, '1', '10', IncludeSet::fromQueryString('profile.preferences'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);

        $profileParents = array_map(static fn ($i) => $i->id, RecordingProfileResolver::$calls[0]['parents']);
        self::assertSame(['1', '5'], $profileParents);

        $prefParents = array_map(static fn ($i) => $i->id, RecordingPreferencesResolver::$calls[0]['parents']);
        self::assertSame(['1-profile', '5-profile'], $prefParents);
    }

    #[Test]
    public function filter_matching_zero_rows_calls_no_resolver(): void
    {
        $this->renderJson('name:contains:zzz', null, '1', '10', IncludeSet::fromQueryString('profile,addresses'));
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function no_include_with_filter_calls_no_resolver(): void
    {
        $this->renderJson('id:in:1,4', null, '1', '10', IncludeSet::empty());
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function repeated_filter_requests_do_not_leak_overlay_state(): void
    {
        $first  = $this->renderJson('id:eq:1', null, '1', '10', IncludeSet::fromQueryString('profile'));
        RecordingProfileResolver::reset();
        $second = $this->renderJson('id:eq:5', null, '1', '10', IncludeSet::fromQueryString('profile'));

        self::assertSame('1', $first['body']['data'][0]['id']);
        self::assertSame('5', $second['body']['data'][0]['id']);
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertSame('5', RecordingProfileResolver::$calls[0]['parents'][0]->id);
    }

    // ----- Invalid filter end-to-end ---------------------------------

    #[Test]
    public function invalid_filter_returns_http_400_via_handler_path(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->renderJson('email:eq:x', null, '1', '10', IncludeSet::empty());
    }

    // ----- JSON-LD + GraphQL profiles --------------------------------

    #[Test]
    public function jsonld_collection_respects_filtered_item_set(): void
    {
        $registry = $this->registry();
        $response = $this->jsonLdResponse($registry);

        $filter = CollectionFilterRequest::fromQueryParam('id:in:1,4', self::FILTER_ALLOW);
        $extractor = static fn (array $r, string $f) => $r[$f] ?? null;

        $filtered = $filter->apply($this->rows(), $extractor);
        $req      = CollectionPageRequest::fromQueryParams('1', '10');
        $page     = CollectionPage::compute($req, count($filtered));
        $sliced   = $req->slice($filtered);
        $customers = [];
        foreach ($sliced as $row) {
            $customers[] = $this->customer($row['id']);
        }

        $context = new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty());
        $response->withResources($customers, $context, CustomerWithBothResolvedResource::class, $page);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'urn:semitexa:phase6e_customer:1',
                'urn:semitexa:phase6e_customer:4',
            ],
            array_column($body['@graph'], '@id'),
        );
        self::assertSame(2, $body['meta']['pagination']['total']);
    }

    #[Test]
    public function graphql_collection_respects_filtered_item_set(): void
    {
        $registry = $this->registry();
        $response = $this->graphqlResponse($registry);

        $filter = CollectionFilterRequest::fromQueryParam('name:contains:acme', self::FILTER_ALLOW);
        $extractor = static fn (array $r, string $f) => $r[$f] ?? null;

        $filtered = $filter->apply($this->rows(), $extractor);
        $req      = CollectionPageRequest::fromQueryParams('1', '10');
        $page     = CollectionPage::compute($req, count($filtered));
        $sliced   = $req->slice($filtered);
        $customers = [];
        foreach ($sliced as $row) {
            $customers[] = $this->customer($row['id']);
        }

        $context = new RenderContext(profile: RenderProfile::GraphQL, includes: IncludeSet::empty());
        $response->withResources('customers', $customers, $context, CustomerWithBothResolvedResource::class, $page);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['1', '5'], array_column($body['data']['customers'], 'id'));
        self::assertSame(2, $body['meta']['pagination']['total']);
    }

    // ----- Single-resource regression --------------------------------

    #[Test]
    public function single_resource_response_remains_unchanged_under_phase_6k(): void
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
        self::assertSame('7', $body['data']['id']);
    }
}
