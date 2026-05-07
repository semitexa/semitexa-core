<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Cursor\CollectionCursor;
use Semitexa\Core\Resource\Cursor\CollectionCursorCodec;
use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Exception\InvalidCursorException;
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
use Semitexa\Core\Resource\Sort\SortDirection;
use Semitexa\Core\Resource\Sort\SortTerm;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerWithBothResolvedResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingAddressesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 6l: cursor pagination layered on the Phase 6h–6k contract.
 * The tests reproduce a typical list handler's flow on synthetic
 * input:
 *
 *   catalog → filter → sort → cursor window → limit → render
 *
 * Cursor mode is mutually exclusive with `?page=`. The codec and
 * the windowing logic together guarantee that cursor parents pick
 * up exactly where the previous page left off, in the effective
 * sort order.
 */
final class Phase6lCursorPaginationIntegrationTest extends TestCase
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
     * Mirror handler flow for cursor mode.
     *
     * @return array{body: array<string, mixed>, cursorPage: CollectionCursorPage}
     */
    private function renderCursor(
        ?string $rawCursor,
        ?string $rawFilter,
        ?string $rawSort,
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

        // Effective sort = user sort + id ASC tie-breaker if not present.
        $hasIdTerm = false;
        foreach ($sort->terms as $term) {
            if ($term->field === 'id') {
                $hasIdTerm = true;
                break;
            }
        }
        $effective = $sort->terms;
        if (!$hasIdTerm) {
            $effective[] = new SortTerm('id', SortDirection::Asc);
        }
        $effectiveSort = new CollectionSortRequest($effective);
        $sorted        = $effectiveSort->apply($filtered, $extractor);

        $codec  = new CollectionCursorCodec();
        $cursor = $codec->decode(
            (string) $rawCursor,
            $sort->toQueryString(),
            $filter->toQueryString(),
        );

        $afterCursor = [];
        foreach ($sorted as $row) {
            if ($this->cursorRowIsBefore($row, $cursor, $effectiveSort, $extractor)) {
                continue;
            }
            $afterCursor[] = $row;
        }

        $req     = CollectionPageRequest::fromQueryParams(null, $rawPerPage);
        $perPage = $req->perPage;
        $visible = array_slice($afterCursor, 0, $perPage);
        $hasNext = count($afterCursor) > $perPage;

        $nextCursor = null;
        if ($hasNext && $visible !== []) {
            $lastRow = $visible[count($visible) - 1];
            $sortKey = [];
            foreach ($sort->terms as $term) {
                $sortKey[] = (string) $extractor($lastRow, $term->field);
            }
            $nextCursor = $codec->encode(new CollectionCursor(
                version:         CollectionCursor::CURRENT_VERSION,
                sortSignature:   $sort->toQueryString(),
                filterSignature: $filter->toQueryString(),
                lastSortKey:     $sortKey,
                lastId:          (string) $lastRow['id'],
            ));
        }

        $cursorPage = new CollectionCursorPage(
            perPage:    $perPage,
            total:      count($filtered),
            hasNext:    $hasNext,
            nextCursor: $nextCursor,
            cursor:     $rawCursor,
        );

        $customers = [];
        foreach ($visible as $row) {
            $customers[] = $this->customer($row['id']);
        }

        $context = new RenderContext(profile: RenderProfile::Json, includes: $includes);
        $response->withResources(
            $customers,
            $context,
            CustomerWithBothResolvedResource::class,
            null,
            $cursorPage,
        );

        return [
            'body'       => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
            'cursorPage' => $cursorPage,
        ];
    }

    /**
     * @return array{body: array<string, mixed>, page: CollectionPage}
     */
    private function renderPage(
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

    /**
     * @param array<string, mixed> $row
     */
    private function cursorRowIsBefore(array $row, CollectionCursor $cursor, CollectionSortRequest $effectiveSort, callable $extractor): bool
    {
        $cursorValues = [...$cursor->lastSortKey, $cursor->lastId];
        foreach ($effectiveSort->terms as $i => $term) {
            $rowVal = (string) $extractor($row, $term->field);
            $curVal = $cursorValues[$i] ?? '';
            $cmp    = $rowVal <=> $curVal;
            if ($cmp === 0) {
                continue;
            }
            return $term->direction === SortDirection::Desc ? $cmp >= 0 : $cmp <= 0;
        }
        return true;
    }

    private function freshCursor(string $sortSig, string $filterSig, array $lastKey, string $lastId): string
    {
        return (new CollectionCursorCodec())->encode(new CollectionCursor(
            version:         CollectionCursor::CURRENT_VERSION,
            sortSignature:   $sortSig,
            filterSignature: $filterSig,
            lastSortKey:     $lastKey,
            lastId:          $lastId,
        ));
    }

    // ----- Page mode regression --------------------------------------

    #[Test]
    public function offset_page_mode_remains_byte_identical_when_cursor_absent(): void
    {
        $r = $this->renderPage(null, null, '1', '10', IncludeSet::empty());
        // Phase 6i shape: `meta.pagination` keys exact set.
        self::assertSame(
            ['page', 'perPage', 'total', 'pageCount', 'hasNext', 'hasPrevious'],
            array_keys($r['body']['meta']['pagination']),
        );
        self::assertArrayNotHasKey('mode', $r['body']['meta']['pagination']);
    }

    // ----- Cursor mode envelope --------------------------------------

    #[Test]
    public function cursor_mode_envelope_carries_cursor_pagination_meta(): void
    {
        // Build a cursor that points after id=1 in sort=name (sorted
        // first item). Next item by name is "AcmeCorp" (id=5).
        $sortSig   = 'name';
        $filterSig = '';
        $cursor    = $this->freshCursor($sortSig, $filterSig, ['Acme'], '1');

        $r = $this->renderCursor($cursor, null, 'name', '1', IncludeSet::empty());
        $meta = $r['body']['meta']['pagination'];

        self::assertSame('cursor', $meta['mode']);
        self::assertSame(1, $meta['perPage']);
        self::assertSame(5, $meta['total']);
        self::assertTrue($meta['hasNext']);
        self::assertNotNull($meta['nextCursor']);
        self::assertSame($cursor, $meta['cursor']);

        // Next visible item is id=5 (AcmeCorp).
        self::assertSame(['5'], array_column($r['body']['data'], 'id'));
    }

    #[Test]
    public function cursor_mode_first_page_with_empty_input_cursor_is_unsupported(): void
    {
        // The handler decides cursor mode by `?cursor=` presence.
        // Empty cursor falls back to page mode in the real handler;
        // calling the codec directly with '' is HTTP 400 (covered in
        // Phase6lCursorCodecTest). This test documents the same
        // expectation by verifying that the fallback page-mode path
        // does NOT emit `meta.mode`.
        $r = $this->renderPage(null, 'name', '1', '1', IncludeSet::empty());
        self::assertArrayNotHasKey('mode', $r['body']['meta']['pagination']);
    }

    #[Test]
    public function next_cursor_is_null_when_there_is_no_next_page(): void
    {
        // Walk to the LAST item by name (asc): Delta(2). Build a
        // cursor that points after Delta — the only "after" element
        // would be... nothing.
        $sortSig   = 'name';
        $filterSig = '';
        $cursor    = $this->freshCursor($sortSig, $filterSig, ['Delta'], '2');

        $r = $this->renderCursor($cursor, null, 'name', '5', IncludeSet::empty());
        $meta = $r['body']['meta']['pagination'];

        self::assertSame([], $r['body']['data']);
        self::assertFalse($meta['hasNext']);
        self::assertNull($meta['nextCursor']);
        self::assertSame(5, $meta['total']);
    }

    // ----- Walking pages with cursors --------------------------------

    #[Test]
    public function client_can_walk_filtered_sorted_stream_with_successive_cursors(): void
    {
        // sort=name, perPage=2 → first page picks Acme(1), AcmeCorp(5).
        // The handler returns nextCursor pointing after AcmeCorp(5).
        $sortSig   = 'name';
        $filterSig = '';

        $cursorAfterAcme = $this->freshCursor($sortSig, $filterSig, ['Acme'], '1');
        $page1 = $this->renderCursor($cursorAfterAcme, null, 'name', '2', IncludeSet::empty());
        // page1 picks AcmeCorp(5), Beta(4) — perPage=2 windows.
        self::assertSame(['5', '4'], array_column($page1['body']['data'], 'id'));
        self::assertTrue($page1['body']['meta']['pagination']['hasNext']);

        $page2 = $this->renderCursor(
            $page1['body']['meta']['pagination']['nextCursor'],
            null,
            'name',
            '2',
            IncludeSet::empty(),
        );
        // Next two by name: Cobalt(3), Delta(2).
        self::assertSame(['3', '2'], array_column($page2['body']['data'], 'id'));
        self::assertFalse($page2['body']['meta']['pagination']['hasNext']);
        self::assertNull($page2['body']['meta']['pagination']['nextCursor']);
    }

    // ----- Filter + sort + cursor composition ------------------------

    #[Test]
    public function cursor_mode_with_filter_uses_filtered_stream(): void
    {
        // filter=id:in:1,4,5; sort=name. Sorted: Acme(1), AcmeCorp(5),
        // Beta(4). Cursor points after Acme(1); next visible is
        // AcmeCorp(5).
        $sortSig   = 'name';
        $filterSig = 'id:in:1,4,5';
        $cursor    = $this->freshCursor($sortSig, $filterSig, ['Acme'], '1');

        $r = $this->renderCursor($cursor, 'id:in:1,4,5', 'name', '1', IncludeSet::empty());
        self::assertSame(['5'], array_column($r['body']['data'], 'id'));
        self::assertTrue($r['body']['meta']['pagination']['hasNext']);
        self::assertSame(3, $r['body']['meta']['pagination']['total']);
    }

    #[Test]
    public function cursor_mode_descending_sort_walks_backwards_through_lex_order(): void
    {
        // sort=-name. Sorted desc: Delta(2), Cobalt(3), Beta(4),
        // AcmeCorp(5), Acme(1). Cursor after Delta(2) → next is
        // Cobalt(3).
        $sortSig   = '-name';
        $filterSig = '';
        $cursor    = $this->freshCursor($sortSig, $filterSig, ['Delta'], '2');

        $r = $this->renderCursor($cursor, null, '-name', '1', IncludeSet::empty());
        self::assertSame(['3'], array_column($r['body']['data'], 'id'));
    }

    // ----- Resolver batching shrinks to cursor window ----------------

    #[Test]
    public function include_profile_with_cursor_passes_only_visible_window_parents(): void
    {
        $sortSig   = 'name';
        $filterSig = '';
        $cursor    = $this->freshCursor($sortSig, $filterSig, ['Acme'], '1');

        $this->renderCursor($cursor, null, 'name', '2', IncludeSet::fromQueryString('profile'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        $ids = array_map(static fn ($i) => $i->id, RecordingProfileResolver::$calls[0]['parents']);
        // Window of 2 after Acme(1): AcmeCorp(5), Beta(4).
        self::assertSame(['5', '4'], $ids);
        self::assertSame([], RecordingAddressesResolver::$calls);
    }

    #[Test]
    public function nested_include_with_cursor_resolves_only_window_profiles(): void
    {
        $sortSig   = 'name';
        $filterSig = '';
        $cursor    = $this->freshCursor($sortSig, $filterSig, ['Acme'], '1');

        $this->renderCursor($cursor, null, 'name', '1', IncludeSet::fromQueryString('profile.preferences'));
        self::assertCount(1, RecordingProfileResolver::$calls);
        self::assertCount(1, RecordingPreferencesResolver::$calls);
        self::assertSame(
            ['5'],
            array_map(static fn ($i) => $i->id, RecordingProfileResolver::$calls[0]['parents']),
        );
        self::assertSame(
            ['5-profile'],
            array_map(static fn ($i) => $i->id, RecordingPreferencesResolver::$calls[0]['parents']),
        );
    }

    #[Test]
    public function cursor_mode_no_include_calls_no_resolver(): void
    {
        $sortSig   = 'name';
        $filterSig = '';
        $cursor    = $this->freshCursor($sortSig, $filterSig, ['Acme'], '1');

        $this->renderCursor($cursor, null, 'name', '5', IncludeSet::empty());
        self::assertSame([], RecordingProfileResolver::$calls);
        self::assertSame([], RecordingAddressesResolver::$calls);
        self::assertSame([], RecordingPreferencesResolver::$calls);
    }

    #[Test]
    public function repeated_cursor_requests_do_not_leak_overlay_state(): void
    {
        $sortSig   = 'name';
        $filterSig = '';

        $cursor1 = $this->freshCursor($sortSig, $filterSig, ['Acme'], '1');
        $first   = $this->renderCursor($cursor1, null, 'name', '1', IncludeSet::fromQueryString('profile'));
        self::assertSame('5', RecordingProfileResolver::$calls[0]['parents'][0]->id);

        RecordingProfileResolver::reset();

        $cursor2 = $this->freshCursor($sortSig, $filterSig, ['AcmeCorp'], '5');
        $second  = $this->renderCursor($cursor2, null, 'name', '1', IncludeSet::fromQueryString('profile'));
        self::assertSame('4', RecordingProfileResolver::$calls[0]['parents'][0]->id);

        self::assertSame('5', $first['body']['data'][0]['id']);
        self::assertSame('4', $second['body']['data'][0]['id']);
    }

    // ----- Mismatched cursor / context -------------------------------

    #[Test]
    public function cursor_with_wrong_sort_signature_is_rejected_at_decode(): void
    {
        // Cursor signed for sort=name; replay against sort=-name.
        $cursor = $this->freshCursor('name', '', ['Acme'], '1');

        $this->expectException(InvalidCursorException::class);
        $this->renderCursor($cursor, null, '-name', '1', IncludeSet::empty());
    }

    #[Test]
    public function cursor_with_wrong_filter_signature_is_rejected_at_decode(): void
    {
        $cursor = $this->freshCursor('name', 'id:in:1,2', ['Acme'], '1');

        $this->expectException(InvalidCursorException::class);
        $this->renderCursor($cursor, 'id:in:3,4', 'name', '1', IncludeSet::empty());
    }

    #[Test]
    public function malformed_cursor_is_rejected_with_400(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->renderCursor('not!a!cursor', null, 'name', '1', IncludeSet::empty());
    }
}
