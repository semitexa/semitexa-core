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
use Semitexa\Core\Resource\Sort\CollectionSortRequest;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6j: sort happens before pagination, and the resolver
 * pipeline only sees the visible sorted slice.
 *
 * The tests mirror a typical handler flow on synthetic input:
 *   catalog rows → sort → paginate/slice → build Resource DTOs →
 *   withResources(... CollectionPage).
 *
 * The fixture rows are intentionally out-of-id-order so that the
 * default order is distinct from `?sort=id`, which lets us assert
 * the sort actually happened.
 */
final class Phase6jCollectionSortIntegrationTest extends TestCase
{
    private const ALLOW = ['id', 'name'];

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

    private function customer(string $id, string $name): CustomerWithBothResolvedResource
    {
        return new CustomerWithBothResolvedResource(
            id: $id,
            // Plain `id` and `name` properties exist on the fixture
            // through the constructor; the `name` is borrowed from the
            // `id` field for readability — fixture has no name field,
            // so we sort on synthetic rows below.
            profile: ResourceRef::to(
                ResourceIdentity::of('profile', $id . '-profile'),
                "/x/{$id}/profile",
            ),
            addresses: ResourceRefList::to("/x/{$id}/addresses"),
        );
    }

    /**
     * Synthetic rows shaped as id + name. The handler's extractor
     * picks the field by key.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        // Out-of-order on both id and name so default ≠ id-asc ≠ name-asc.
        return [
            ['id' => '3', 'name' => 'Cobalt'],
            ['id' => '1', 'name' => 'Acme'],
            ['id' => '4', 'name' => 'Beta'],
            ['id' => '2', 'name' => 'Delta'],
            ['id' => '5', 'name' => 'Epsilon'],
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
     * Mirror handler flow: sort → page → slice → build → render.
     *
     * @return array{body: array<string, mixed>, page: CollectionPage}
     */
    private function renderJson(?string $rawSort, string $rawPage, string $rawPerPage, IncludeSet $includes): array
    {
        $registry = $this->registry();
        $response = $this->jsonResponse($registry);

        $sort   = CollectionSortRequest::fromQueryParam($rawSort, self::ALLOW);
        $rows   = $sort->apply($this->rows(), static fn (array $r, string $f) => $r[$f] ?? null);
        $req    = CollectionPageRequest::fromQueryParams($rawPage, $rawPerPage);
        $page   = CollectionPage::compute($req, count($rows));
        $sliced = $req->slice($rows);

        $customers = [];
        foreach ($sliced as $row) {
            $customers[] = $this->customer($row['id'], $row['name']);
        }

        $context = new RenderContext(profile: RenderProfile::Json, includes: $includes);
        $response->withResources($customers, $context, CustomerWithBothResolvedResource::class, $page);

        return [
            'body' => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
            'page' => $page,
        ];
    }

    // ----- Default order preserved when sort is omitted ---------------

    #[Test]
    public function omitted_sort_preserves_default_catalog_order(): void
    {
        $r = $this->renderJson(null, '1', '10', IncludeSet::empty());
        // Default catalog order is 3, 1, 4, 2, 5 (insertion order).
        self::assertSame(['3', '1', '4', '2', '5'], array_column($r['body']['data'], 'id'));
    }

    #[Test]
    public function empty_sort_string_treated_as_omitted(): void
    {
        $r = $this->renderJson('', '1', '10', IncludeSet::empty());
        self::assertSame(['3', '1', '4', '2', '5'], array_column($r['body']['data'], 'id'));
    }

    // ----- Sort orders -----------------------------------------------

    #[Test]
    public function sort_id_ascending(): void
    {
        $r = $this->renderJson('id', '1', '10', IncludeSet::empty());
        self::assertSame(['1', '2', '3', '4', '5'], array_column($r['body']['data'], 'id'));
    }

    #[Test]
    public function sort_id_descending(): void
    {
        $r = $this->renderJson('-id', '1', '10', IncludeSet::empty());
        self::assertSame(['5', '4', '3', '2', '1'], array_column($r['body']['data'], 'id'));
    }

    #[Test]
    public function sort_name_ascending_uses_name_value_not_id(): void
    {
        $r = $this->renderJson('name', '1', '10', IncludeSet::empty());
        // Names are: Cobalt, Acme, Beta, Delta, Epsilon → asc:
        //   Acme(1), Beta(4), Cobalt(3), Delta(2), Epsilon(5)
        self::assertSame(['1', '4', '3', '2', '5'], array_column($r['body']['data'], 'id'));
    }

    #[Test]
    public function sort_name_descending(): void
    {
        $r = $this->renderJson('-name', '1', '10', IncludeSet::empty());
        self::assertSame(['5', '2', '3', '4', '1'], array_column($r['body']['data'], 'id'));
    }

    // ----- Sort happens BEFORE pagination ----------------------------

    #[Test]
    public function sort_then_paginate_returns_first_sorted_item(): void
    {
        // sort=name&page=1&perPage=1 → Acme(id=1) is the first sorted
        // customer; the slice picks that one.
        $r = $this->renderJson('name', '1', '1', IncludeSet::empty());
        self::assertCount(1, $r['body']['data']);
        self::assertSame('1', $r['body']['data'][0]['id']);
        self::assertSame(5, $r['body']['meta']['pagination']['total']);
        self::assertTrue($r['body']['meta']['pagination']['hasNext']);
    }

    #[Test]
    public function sort_then_paginate_walks_pages_in_sorted_order(): void
    {
        // Walk all pages of size 2 with sort=name; concatenated they
        // must equal the fully-sorted-by-name id sequence.
        $page1 = $this->renderJson('name', '1', '2', IncludeSet::empty());
        $page2 = $this->renderJson('name', '2', '2', IncludeSet::empty());
        $page3 = $this->renderJson('name', '3', '2', IncludeSet::empty());

        $ids = array_merge(
            array_column($page1['body']['data'], 'id'),
            array_column($page2['body']['data'], 'id'),
            array_column($page3['body']['data'], 'id'),
        );
        self::assertSame(['1', '4', '3', '2', '5'], $ids);
    }

    #[Test]
    public function sort_with_descending_then_pagination_picks_last_in_default_order_first(): void
    {
        // sort=-id&page=1&perPage=1 → first item is highest id.
        $r = $this->renderJson('-id', '1', '1', IncludeSet::empty());
        self::assertSame('5', $r['body']['data'][0]['id']);
    }

    #[Test]
    public function multi_field_sort_breaks_ties_deterministically(): void
    {
        $r = $this->renderJson('name,id', '1', '10', IncludeSet::empty());
        // No name ties in this fixture, but the multi-field path is
        // exercised end-to-end; result equals sort=name only.
        self::assertSame(['1', '4', '3', '2', '5'], array_column($r['body']['data'], 'id'));
    }

    // ----- Resolver batching only sees paged sorted parents ----------

    #[Test]
    public function include_profile_with_sort_calls_resolver_with_paged_sorted_parents(): void
    {
        // sort=name&page=1&perPage=1 → only customer id=1 is visible.
        $this->renderJson('name', '1', '1', IncludeSet::fromQueryString('profile'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingProfileResolver::$calls[0]['parents']);
        self::assertSame('1', RecordingProfileResolver::$calls[0]['parents'][0]->id);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function include_profile_with_sort_paged_two_resolves_in_sorted_order(): void
    {
        $this->renderJson('name', '1', '2', IncludeSet::fromQueryString('profile'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        $ids = array_map(static fn ($i) => $i->id, RecordingProfileResolver::$calls[0]['parents']);
        // First two by name: Acme(1), Beta(4).
        self::assertSame(['1', '4'], $ids);
    }

    #[Test]
    public function include_addresses_with_sort_calls_addresses_resolver_with_paged_parents(): void
    {
        $this->renderJson('-id', '1', '2', IncludeSet::fromQueryString('addresses'));
        self::assertCount(1, RecordingAddressesResolver::$calls);
        $ids = array_map(static fn ($i) => $i->id, RecordingAddressesResolver::$calls[0]['parents']);
        // Top two by descending id: 5, 4.
        self::assertSame(['5', '4'], $ids);
        self::assertSame([], RecordingProfileResolver::$calls);
    }

    #[Test]
    public function include_profile_preferences_with_sort_runs_two_buckets_in_sorted_order(): void
    {
        $this->renderJson('name', '1', '2', IncludeSet::fromQueryString('profile.preferences'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);

        $profileParents = array_map(static fn ($i) => $i->id, RecordingProfileResolver::$calls[0]['parents']);
        self::assertSame(['1', '4'], $profileParents);

        $prefParents = array_map(static fn ($i) => $i->id, RecordingPreferencesResolver::$calls[0]['parents']);
        // Nested resolver receives Profile identities — the resolver
        // builds them as `<customerId>-profile`, so the order tracks
        // the sorted customer page.
        self::assertSame(['1-profile', '4-profile'], $prefParents);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function no_include_with_sort_calls_no_resolver(): void
    {
        $this->renderJson('-id', '1', '5', IncludeSet::empty());
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function repeated_sort_requests_do_not_leak_overlay_state(): void
    {
        $first  = $this->renderJson('name',  '1', '1', IncludeSet::fromQueryString('profile'));
        RecordingProfileResolver::reset();
        $second = $this->renderJson('-name', '1', '1', IncludeSet::fromQueryString('profile'));

        self::assertSame('1', $first['body']['data'][0]['id']);
        self::assertSame('5', $second['body']['data'][0]['id']);
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertSame('5', RecordingProfileResolver::$calls[0]['parents'][0]->id);
    }

    // ----- JSON-LD + GraphQL profiles --------------------------------

    #[Test]
    public function jsonld_collection_order_follows_sort(): void
    {
        $registry = $this->registry();
        $response = $this->jsonLdResponse($registry);

        $sort   = CollectionSortRequest::fromQueryParam('-id', self::ALLOW);
        $rows   = $sort->apply($this->rows(), static fn (array $r, string $f) => $r[$f] ?? null);
        $req    = CollectionPageRequest::fromQueryParams('1', '3');
        $page   = CollectionPage::compute($req, count($rows));
        $sliced = $req->slice($rows);
        $customers = [];
        foreach ($sliced as $row) {
            $customers[] = $this->customer($row['id'], $row['name']);
        }

        $context = new RenderContext(profile: RenderProfile::JsonLd, includes: IncludeSet::empty());
        $response->withResources($customers, $context, CustomerWithBothResolvedResource::class, $page);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        // Top three by descending id: 5, 4, 3.
        self::assertSame(
            [
                'urn:semitexa:phase6e_customer:5',
                'urn:semitexa:phase6e_customer:4',
                'urn:semitexa:phase6e_customer:3',
            ],
            array_column($body['@graph'], '@id'),
        );
    }

    #[Test]
    public function graphql_collection_order_follows_sort(): void
    {
        $registry = $this->registry();
        $response = $this->graphqlResponse($registry);

        $sort   = CollectionSortRequest::fromQueryParam('name', self::ALLOW);
        $rows   = $sort->apply($this->rows(), static fn (array $r, string $f) => $r[$f] ?? null);
        $req    = CollectionPageRequest::fromQueryParams('1', '5');
        $page   = CollectionPage::compute($req, count($rows));
        $sliced = $req->slice($rows);
        $customers = [];
        foreach ($sliced as $row) {
            $customers[] = $this->customer($row['id'], $row['name']);
        }

        $context = new RenderContext(profile: RenderProfile::GraphQL, includes: IncludeSet::empty());
        $response->withResources('customers', $customers, $context, CustomerWithBothResolvedResource::class, $page);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['1', '4', '3', '2', '5'],
            array_column($body['data']['customers'], 'id'),
        );
    }

    // ----- Single-resource regression --------------------------------

    #[Test]
    public function single_resource_response_remains_unchanged_under_phase_6j(): void
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
        $response->withResource($this->customer('7', 'Acme'), $context);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['data'], array_keys($body));
        self::assertArrayNotHasKey('meta', $body);
        self::assertSame('7', $body['data']['id']);
    }
}
