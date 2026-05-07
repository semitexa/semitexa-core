<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\InvalidSortException;
use Semitexa\Core\Resource\Sort\CollectionSortRequest;
use Semitexa\Core\Resource\Sort\SortDirection;

/**
 * Phase 6j: deterministic `?sort=` parser + comparator.
 *
 * The parser is the only place where raw `?sort=` strings turn into
 * resolved `(field, direction)` pairs. Validation rejects every
 * shape outside the bounded grammar with `InvalidSortException`
 * (HTTP 400). The `apply()` step is purely in-memory; the VO does
 * not know the row type — callers supply an extractor.
 */
final class Phase6jCollectionSortRequestTest extends TestCase
{
    private const ALLOW = ['id', 'name'];

    #[Test]
    public function null_sort_yields_empty_terms_for_default_order(): void
    {
        $sort = CollectionSortRequest::fromQueryParam(null, self::ALLOW);
        self::assertTrue($sort->isEmpty());
        self::assertSame([], $sort->terms);
    }

    #[Test]
    public function empty_sort_yields_empty_terms_for_default_order(): void
    {
        $sort = CollectionSortRequest::fromQueryParam('', self::ALLOW);
        self::assertTrue($sort->isEmpty());

        $sort = CollectionSortRequest::fromQueryParam('   ', self::ALLOW);
        self::assertTrue($sort->isEmpty());
    }

    #[Test]
    public function single_field_ascending_passes(): void
    {
        $sort = CollectionSortRequest::fromQueryParam('name', self::ALLOW);
        self::assertCount(1, $sort->terms);
        self::assertSame('name', $sort->terms[0]->field);
        self::assertSame(SortDirection::Asc, $sort->terms[0]->direction);
    }

    #[Test]
    public function single_field_descending_passes(): void
    {
        $sort = CollectionSortRequest::fromQueryParam('-name', self::ALLOW);
        self::assertCount(1, $sort->terms);
        self::assertSame('name', $sort->terms[0]->field);
        self::assertSame(SortDirection::Desc, $sort->terms[0]->direction);
    }

    #[Test]
    public function id_field_passes_in_both_directions(): void
    {
        self::assertSame(SortDirection::Asc, CollectionSortRequest::fromQueryParam('id',  self::ALLOW)->terms[0]->direction);
        self::assertSame(SortDirection::Desc, CollectionSortRequest::fromQueryParam('-id', self::ALLOW)->terms[0]->direction);
    }

    #[Test]
    public function multi_field_sort_preserves_left_to_right_order(): void
    {
        $sort = CollectionSortRequest::fromQueryParam('-name,id', self::ALLOW);
        self::assertCount(2, $sort->terms);
        self::assertSame('name', $sort->terms[0]->field);
        self::assertSame(SortDirection::Desc, $sort->terms[0]->direction);
        self::assertSame('id', $sort->terms[1]->field);
        self::assertSame(SortDirection::Asc, $sort->terms[1]->direction);
    }

    #[Test]
    public function rejects_unknown_field_with_400(): void
    {
        $this->expectException(InvalidSortException::class);
        $this->expectExceptionMessageMatches('/not in the route allowlist/');
        CollectionSortRequest::fromQueryParam('unknown', self::ALLOW);
    }

    #[Test]
    public function rejects_dotted_relation_field_with_400(): void
    {
        $this->expectException(InvalidSortException::class);
        $this->expectExceptionMessageMatches('/nested \/ relation field/');
        CollectionSortRequest::fromQueryParam('profile.bio', self::ALLOW);
    }

    #[Test]
    public function rejects_double_minus_with_400(): void
    {
        $this->expectException(InvalidSortException::class);
        $this->expectExceptionMessageMatches('/double minus/');
        CollectionSortRequest::fromQueryParam('--name', self::ALLOW);
    }

    #[Test]
    public function rejects_empty_term_in_comma_list_with_400(): void
    {
        $this->expectException(InvalidSortException::class);
        $this->expectExceptionMessageMatches('/empty term/');
        CollectionSortRequest::fromQueryParam('name,,id', self::ALLOW);
    }

    #[Test]
    public function rejects_minus_only_with_400(): void
    {
        $this->expectException(InvalidSortException::class);
        $this->expectExceptionMessageMatches('/empty after direction prefix/');
        CollectionSortRequest::fromQueryParam('-', self::ALLOW);
    }

    #[Test]
    public function rejects_duplicate_field_in_multi_sort_with_400(): void
    {
        $this->expectException(InvalidSortException::class);
        $this->expectExceptionMessageMatches('/appears more than once/');
        CollectionSortRequest::fromQueryParam('name,-name', self::ALLOW);
    }

    #[Test]
    public function rejects_unknown_field_in_multi_sort_with_400(): void
    {
        $this->expectException(InvalidSortException::class);
        $this->expectExceptionMessageMatches('/not in the route allowlist/');
        CollectionSortRequest::fromQueryParam('id,unknown', self::ALLOW);
    }

    #[Test]
    public function exception_carries_status_400_and_allowlist(): void
    {
        try {
            CollectionSortRequest::fromQueryParam('unknown', self::ALLOW);
            self::fail('Expected InvalidSortException.');
        } catch (InvalidSortException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertSame(self::ALLOW, $e->allowedFields);
            self::assertSame('unknown', $e->rawValue);
        }
    }

    #[Test]
    public function apply_with_empty_request_returns_input_order(): void
    {
        $items = [['id' => 'b'], ['id' => 'a']];
        $sort  = CollectionSortRequest::fromQueryParam(null, self::ALLOW);
        self::assertSame($items, $sort->apply($items, fn ($r, $f) => $r[$f] ?? null));
    }

    #[Test]
    public function apply_ascending_sort(): void
    {
        $items = [['id' => 'b'], ['id' => 'a'], ['id' => 'c']];
        $sort  = CollectionSortRequest::fromQueryParam('id', self::ALLOW);
        $out   = $sort->apply($items, fn ($r, $f) => $r[$f] ?? null);
        self::assertSame(['a', 'b', 'c'], array_column($out, 'id'));
    }

    #[Test]
    public function apply_descending_sort(): void
    {
        $items = [['id' => 'b'], ['id' => 'a'], ['id' => 'c']];
        $sort  = CollectionSortRequest::fromQueryParam('-id', self::ALLOW);
        $out   = $sort->apply($items, fn ($r, $f) => $r[$f] ?? null);
        self::assertSame(['c', 'b', 'a'], array_column($out, 'id'));
    }

    #[Test]
    public function apply_multi_field_sort_with_tie_breaker(): void
    {
        // Two items share name 'B'; the secondary sort by id breaks
        // the tie deterministically.
        $items = [
            ['id' => '2', 'name' => 'A'],
            ['id' => '5', 'name' => 'B'],
            ['id' => '3', 'name' => 'B'],
            ['id' => '1', 'name' => 'A'],
        ];
        $sort = CollectionSortRequest::fromQueryParam('name,id', self::ALLOW);
        $out  = $sort->apply($items, fn ($r, $f) => $r[$f] ?? null);
        self::assertSame(['1', '2', '3', '5'], array_column($out, 'id'));
    }

    #[Test]
    public function apply_is_stable_under_full_tie(): void
    {
        // All items compare equal under the requested sort; the input
        // order must be preserved.
        $items = [
            ['id' => 'x', 'name' => 'A'],
            ['id' => 'x', 'name' => 'A'],
            ['id' => 'x', 'name' => 'A'],
        ];
        $sort = CollectionSortRequest::fromQueryParam('name', self::ALLOW);
        $out  = $sort->apply($items, fn ($r, $f) => $r[$f] ?? null);
        self::assertSame($items, $out);
    }

    #[Test]
    public function apply_does_not_mutate_input_list(): void
    {
        $items    = [['id' => 'b'], ['id' => 'a']];
        $snapshot = $items;
        $sort     = CollectionSortRequest::fromQueryParam('id', self::ALLOW);
        $sort->apply($items, fn ($r, $f) => $r[$f] ?? null);
        self::assertSame($snapshot, $items);
    }

    #[Test]
    public function to_query_string_round_trips_through_from_query_param(): void
    {
        $original = CollectionSortRequest::fromQueryParam('-name,id', self::ALLOW);
        self::assertSame('-name,id', $original->toQueryString());

        $rebuilt = CollectionSortRequest::fromQueryParam($original->toQueryString(), self::ALLOW);
        self::assertEquals($original, $rebuilt);
    }

    #[Test]
    public function empty_allowlist_rejects_every_non_empty_sort(): void
    {
        $this->expectException(InvalidSortException::class);
        CollectionSortRequest::fromQueryParam('id', []);
    }
}
