<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\InvalidPaginationException;
use Semitexa\Core\Resource\Pagination\CollectionPage;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;

/**
 * Phase 6i: deterministic pagination request parser + page metadata.
 *
 * The parser is the only place where raw `?page=` / `?perPage=`
 * strings turn into integers; once a `CollectionPageRequest` exists
 * its values are trusted. Validation rejects every invalid shape
 * with `InvalidPaginationException` (HTTP 400).
 */
final class Phase6iCollectionPageRequestTest extends TestCase
{
    #[Test]
    public function defaults_apply_when_both_parameters_are_omitted(): void
    {
        $req = CollectionPageRequest::fromQueryParams(null, null);
        self::assertSame(CollectionPageRequest::DEFAULT_PAGE, $req->page);
        self::assertSame(CollectionPageRequest::DEFAULT_PER_PAGE, $req->perPage);
    }

    #[Test]
    public function empty_string_is_treated_as_omitted(): void
    {
        $req = CollectionPageRequest::fromQueryParams('', '');
        self::assertSame(CollectionPageRequest::DEFAULT_PAGE, $req->page);
        self::assertSame(CollectionPageRequest::DEFAULT_PER_PAGE, $req->perPage);
    }

    #[Test]
    public function valid_explicit_page_and_per_page_round_trip(): void
    {
        $req = CollectionPageRequest::fromQueryParams('3', '7');
        self::assertSame(3, $req->page);
        self::assertSame(7, $req->perPage);
    }

    #[Test]
    public function offset_is_zero_based_and_derived_from_page_and_per_page(): void
    {
        self::assertSame(0,  CollectionPageRequest::fromQueryParams('1', '10')->offset());
        self::assertSame(10, CollectionPageRequest::fromQueryParams('2', '10')->offset());
        self::assertSame(7,  CollectionPageRequest::fromQueryParams('2', '7')->offset());
    }

    #[Test]
    public function slice_returns_paged_window_in_order(): void
    {
        $items = ['a', 'b', 'c', 'd', 'e'];
        self::assertSame(['a', 'b'], CollectionPageRequest::fromQueryParams('1', '2')->slice($items));
        self::assertSame(['c', 'd'], CollectionPageRequest::fromQueryParams('2', '2')->slice($items));
        self::assertSame(['e'],      CollectionPageRequest::fromQueryParams('3', '2')->slice($items));
    }

    #[Test]
    public function slice_beyond_last_page_returns_empty_list(): void
    {
        $items = ['a', 'b'];
        self::assertSame([], CollectionPageRequest::fromQueryParams('999', '10')->slice($items));
    }

    #[Test]
    public function rejects_zero_page_with_400(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/page.*must be >= 1/');
        CollectionPageRequest::fromQueryParams('0', null);
    }

    #[Test]
    public function rejects_negative_page_with_400(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/page.*must be >= 1/');
        CollectionPageRequest::fromQueryParams('-3', null);
    }

    #[Test]
    public function rejects_non_integer_page_with_400(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/page.*must be an integer/');
        CollectionPageRequest::fromQueryParams('abc', null);
    }

    #[Test]
    public function rejects_decimal_page_with_400(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/page.*must be an integer/');
        CollectionPageRequest::fromQueryParams('1.5', null);
    }

    #[Test]
    public function rejects_zero_per_page_with_400(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/perPage.*must be >= 1/');
        CollectionPageRequest::fromQueryParams(null, '0');
    }

    #[Test]
    public function rejects_negative_per_page_with_400(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/perPage.*must be >= 1/');
        CollectionPageRequest::fromQueryParams(null, '-2');
    }

    #[Test]
    public function rejects_non_integer_per_page_with_400(): void
    {
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/perPage.*must be an integer/');
        CollectionPageRequest::fromQueryParams(null, 'lots');
    }

    #[Test]
    public function rejects_per_page_above_maximum_with_400(): void
    {
        $tooBig = (string) (CollectionPageRequest::MAX_PER_PAGE + 1);
        $this->expectException(InvalidPaginationException::class);
        $this->expectExceptionMessageMatches('/perPage.*must be <= /');
        CollectionPageRequest::fromQueryParams(null, $tooBig);
    }

    #[Test]
    public function exception_carries_status_400(): void
    {
        try {
            CollectionPageRequest::fromQueryParams('0', null);
            self::fail('Expected InvalidPaginationException.');
        } catch (InvalidPaginationException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertSame('page', $e->parameter);
            self::assertSame('0', $e->rawValue);
        }
    }

    #[Test]
    public function constructor_revalidates_for_test_factories(): void
    {
        // The public constructor is exposed for tests that need a
        // VO without re-parsing strings — it must still reject
        // out-of-range values.
        $this->expectException(InvalidPaginationException::class);
        new CollectionPageRequest(0, 10);
    }

    #[Test]
    public function compute_handles_full_collection_smaller_than_one_page(): void
    {
        $req = CollectionPageRequest::fromQueryParams('1', '10');
        $page = CollectionPage::compute($req, 2);
        self::assertSame(1, $page->page);
        self::assertSame(10, $page->perPage);
        self::assertSame(2, $page->total);
        self::assertSame(1, $page->pageCount);
        self::assertFalse($page->hasNext);
        self::assertFalse($page->hasPrevious);
    }

    #[Test]
    public function compute_handles_exact_full_page(): void
    {
        $req  = CollectionPageRequest::fromQueryParams('1', '5');
        $page = CollectionPage::compute($req, 5);
        self::assertSame(1, $page->pageCount);
        self::assertFalse($page->hasNext);
    }

    #[Test]
    public function compute_handles_partial_last_page(): void
    {
        $req  = CollectionPageRequest::fromQueryParams('3', '4');
        $page = CollectionPage::compute($req, 9);
        self::assertSame(3, $page->pageCount);
        self::assertFalse($page->hasNext);
        self::assertTrue($page->hasPrevious);
    }

    #[Test]
    public function compute_marks_has_next_when_more_pages_available(): void
    {
        $req  = CollectionPageRequest::fromQueryParams('1', '2');
        $page = CollectionPage::compute($req, 5);
        self::assertSame(3, $page->pageCount);
        self::assertTrue($page->hasNext);
        self::assertFalse($page->hasPrevious);
    }

    #[Test]
    public function compute_handles_empty_collection(): void
    {
        $req  = CollectionPageRequest::fromQueryParams('1', '10');
        $page = CollectionPage::compute($req, 0);
        self::assertSame(0, $page->total);
        self::assertSame(0, $page->pageCount);
        self::assertFalse($page->hasNext);
        self::assertFalse($page->hasPrevious);
    }

    #[Test]
    public function compute_accepts_page_beyond_last_without_throwing(): void
    {
        // The framework chooses lenient out-of-range behaviour: a
        // `page` past the last page yields an empty data array with
        // valid metadata. The slice logic returns `[]`, and
        // `compute()` reports `hasNext=false, hasPrevious=true`.
        $req  = CollectionPageRequest::fromQueryParams('999', '10');
        $page = CollectionPage::compute($req, 5);
        self::assertSame(999, $page->page);
        self::assertSame(1, $page->pageCount);
        self::assertFalse($page->hasNext);
        self::assertTrue($page->hasPrevious);

        // The slice helper returns an empty list deterministically.
        self::assertSame([], $req->slice([1, 2, 3, 4, 5]));
    }

    #[Test]
    public function to_array_emits_canonical_meta_pagination_shape(): void
    {
        $page = CollectionPage::compute(
            CollectionPageRequest::fromQueryParams('2', '3'),
            10,
        );
        self::assertSame(
            [
                'page'        => 2,
                'perPage'     => 3,
                'total'       => 10,
                'pageCount'   => 4,
                'hasNext'     => true,
                'hasPrevious' => true,
            ],
            $page->toArray(),
        );
    }
}
