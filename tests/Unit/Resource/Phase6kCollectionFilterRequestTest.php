<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\InvalidFilterException;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Core\Resource\Filter\FilterOperator;

/**
 * Phase 6k: deterministic `?filter=` parser + comparator.
 *
 * The parser is the only place where raw `?filter=` strings turn
 * into resolved `(field, operator, value)` tuples. Validation
 * rejects every shape outside the bounded grammar with
 * `InvalidFilterException` (HTTP 400). The `apply()` step is purely
 * in-memory; the VO does not know the row type — callers supply an
 * extractor.
 */
final class Phase6kCollectionFilterRequestTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const ALLOW = [
        'id'   => ['eq', 'in'],
        'name' => ['eq', 'contains'],
    ];

    // ----- Default / empty paths -------------------------------------

    #[Test]
    public function null_filter_yields_empty_request_for_no_filtering(): void
    {
        $f = CollectionFilterRequest::fromQueryParam(null, self::ALLOW);
        self::assertTrue($f->isEmpty());
        self::assertSame([], $f->terms);
    }

    #[Test]
    public function empty_filter_yields_empty_request_for_no_filtering(): void
    {
        self::assertTrue(CollectionFilterRequest::fromQueryParam('',    self::ALLOW)->isEmpty());
        self::assertTrue(CollectionFilterRequest::fromQueryParam('   ', self::ALLOW)->isEmpty());
    }

    // ----- Valid grammar ---------------------------------------------

    #[Test]
    public function id_eq_passes(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('id:eq:1', self::ALLOW);
        self::assertCount(1, $f->terms);
        self::assertSame('id', $f->terms[0]->field);
        self::assertSame(FilterOperator::Eq, $f->terms[0]->operator);
        self::assertSame('1', $f->terms[0]->value);
    }

    #[Test]
    public function id_in_list_passes(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('id:in:1,2,3', self::ALLOW);
        self::assertCount(1, $f->terms);
        self::assertSame(FilterOperator::In, $f->terms[0]->operator);
        self::assertSame(['1', '2', '3'], $f->terms[0]->value);
    }

    #[Test]
    public function name_eq_passes(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('name:eq:Acme', self::ALLOW);
        self::assertSame('Acme', $f->terms[0]->value);
    }

    #[Test]
    public function name_contains_passes(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('name:contains:acme', self::ALLOW);
        self::assertSame(FilterOperator::Contains, $f->terms[0]->operator);
        self::assertSame('acme', $f->terms[0]->value);
    }

    #[Test]
    public function multi_term_request_preserves_left_to_right_order(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('id:in:1,2,3;name:contains:acme', self::ALLOW);
        self::assertCount(2, $f->terms);
        self::assertSame('id',   $f->terms[0]->field);
        self::assertSame('name', $f->terms[1]->field);
    }

    #[Test]
    public function value_may_contain_colons_after_the_first_two_separators(): void
    {
        // The parser splits on the first two `:` separators only, so
        // a value like `12:34:56` round-trips as the literal string.
        $allow = ['note' => ['eq']];
        $f = CollectionFilterRequest::fromQueryParam('note:eq:12:34:56', $allow);
        self::assertSame('12:34:56', $f->terms[0]->value);
    }

    // ----- Allowlist + grammar rejections ----------------------------

    #[Test]
    public function rejects_unknown_field_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/not in the route filter allowlist/');
        CollectionFilterRequest::fromQueryParam('email:eq:x', self::ALLOW);
    }

    #[Test]
    public function rejects_nested_field_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/nested \/ relation field/');
        CollectionFilterRequest::fromQueryParam('profile.bio:eq:x', self::ALLOW);
    }

    #[Test]
    public function rejects_unsupported_operator_for_field_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/operator "contains" is not allowed on field "id"/');
        CollectionFilterRequest::fromQueryParam('id:contains:1', self::ALLOW);
    }

    #[Test]
    public function rejects_off_allowlist_operator_for_name_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/operator "in" is not allowed on field "name"/');
        CollectionFilterRequest::fromQueryParam('name:in:a,b', self::ALLOW);
    }

    #[Test]
    public function rejects_malformed_no_operator_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/missing operator separator/');
        CollectionFilterRequest::fromQueryParam('bad', self::ALLOW);
    }

    #[Test]
    public function rejects_malformed_no_value_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/missing value separator/');
        CollectionFilterRequest::fromQueryParam('name:eq', self::ALLOW);
    }

    #[Test]
    public function rejects_empty_value_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/has empty value/');
        CollectionFilterRequest::fromQueryParam('id:eq:', self::ALLOW);
    }

    #[Test]
    public function rejects_empty_term_in_semicolon_list_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/empty term in semicolon-separated/');
        CollectionFilterRequest::fromQueryParam('id:eq:1;;name:eq:Acme', self::ALLOW);
    }

    #[Test]
    public function rejects_empty_in_list_entry_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/empty entry in "in" list/');
        CollectionFilterRequest::fromQueryParam('id:in:1,,3', self::ALLOW);
    }

    #[Test]
    public function rejects_duplicate_field_operator_pair_with_400(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessageMatches('/appears more than once/');
        CollectionFilterRequest::fromQueryParam('id:eq:1;id:eq:2', self::ALLOW);
    }

    #[Test]
    public function allows_two_different_operators_on_same_field(): void
    {
        // `id:eq:1;id:in:1,2,3` is NOT a duplicate because the
        // (field, operator) pair differs. AND semantics apply, so the
        // result is the AND intersection — useful for "id is X" tied
        // with "id is in some allowlist" guards.
        $f = CollectionFilterRequest::fromQueryParam('id:eq:1;id:in:1,2,3', self::ALLOW);
        self::assertCount(2, $f->terms);
    }

    #[Test]
    public function exception_carries_status_400_and_allowlist(): void
    {
        try {
            CollectionFilterRequest::fromQueryParam('email:eq:x', self::ALLOW);
            self::fail('Expected InvalidFilterException.');
        } catch (InvalidFilterException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertSame(self::ALLOW, $e->allowedFilters);
            self::assertSame('email:eq:x', $e->rawValue);
        }
    }

    #[Test]
    public function empty_allowlist_rejects_every_non_empty_filter(): void
    {
        $this->expectException(InvalidFilterException::class);
        CollectionFilterRequest::fromQueryParam('id:eq:1', []);
    }

    // ----- Apply (AND semantics) -------------------------------------

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

    private function extractor(): callable
    {
        return static fn (array $row, string $field): mixed => $row[$field] ?? null;
    }

    #[Test]
    public function apply_with_empty_request_returns_input_order_unchanged(): void
    {
        $f = CollectionFilterRequest::fromQueryParam(null, self::ALLOW);
        self::assertSame($this->rows(), $f->apply($this->rows(), $this->extractor()));
    }

    #[Test]
    public function apply_id_eq_returns_matching_row(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('id:eq:2', self::ALLOW);
        $out = $f->apply($this->rows(), $this->extractor());
        self::assertSame([['id' => '2', 'name' => 'Delta']], $out);
    }

    #[Test]
    public function apply_id_in_returns_all_matching_rows_in_input_order(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('id:in:1,4,5', self::ALLOW);
        $out = $f->apply($this->rows(), $this->extractor());
        // Input order preserved: 1, 4, 5 in source positions [1, 2, 4].
        self::assertSame(['1', '4', '5'], array_column($out, 'id'));
    }

    #[Test]
    public function apply_name_eq_is_case_sensitive(): void
    {
        // Exact equality after string cast — case sensitive by design.
        $hit  = CollectionFilterRequest::fromQueryParam('name:eq:Acme', self::ALLOW);
        $miss = CollectionFilterRequest::fromQueryParam('name:eq:acme', self::ALLOW);

        self::assertSame(['1'], array_column($hit->apply($this->rows(), $this->extractor()), 'id'));
        self::assertSame([],   $miss->apply($this->rows(), $this->extractor()));
    }

    #[Test]
    public function apply_name_contains_is_case_insensitive(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('name:contains:acme', self::ALLOW);
        $out = $f->apply($this->rows(), $this->extractor());
        // Both "Acme" and "AcmeCorp" match a case-insensitive
        // substring search for "acme".
        self::assertSame(['1', '5'], array_column($out, 'id'));
    }

    #[Test]
    public function apply_multi_term_uses_and_semantics(): void
    {
        $f = CollectionFilterRequest::fromQueryParam(
            'id:in:1,2,3,4,5;name:contains:acme',
            self::ALLOW,
        );
        $out = $f->apply($this->rows(), $this->extractor());
        // id ∈ {1..5} ∧ name contains "acme" → Acme(1), AcmeCorp(5).
        self::assertSame(['1', '5'], array_column($out, 'id'));
    }

    #[Test]
    public function apply_with_filter_that_matches_zero_rows_returns_empty_list(): void
    {
        $f = CollectionFilterRequest::fromQueryParam('name:contains:zzz', self::ALLOW);
        self::assertSame([], $f->apply($this->rows(), $this->extractor()));
    }

    #[Test]
    public function apply_does_not_mutate_input_list(): void
    {
        $rows     = $this->rows();
        $snapshot = $rows;
        $f        = CollectionFilterRequest::fromQueryParam('id:eq:1', self::ALLOW);
        $f->apply($rows, $this->extractor());
        self::assertSame($snapshot, $rows);
    }

    #[Test]
    public function to_query_string_round_trips_through_from_query_param(): void
    {
        $original = CollectionFilterRequest::fromQueryParam(
            'id:in:1,2,3;name:contains:acme',
            self::ALLOW,
        );
        self::assertSame('id:in:1,2,3;name:contains:acme', $original->toQueryString());

        $rebuilt = CollectionFilterRequest::fromQueryParam(
            $original->toQueryString(),
            self::ALLOW,
        );
        self::assertEquals($original, $rebuilt);
    }
}
