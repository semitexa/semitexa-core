<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\InvalidFilterException;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Core\Resource\Filter\FilterOperator;

/**
 * tk-rs-range-filters: `gte` / `lte` give a range ("price from 10 to 20",
 * "launched in October") — numbers compared as numbers, ISO dates as text,
 * both ends allowed on one field.
 */
final class RangeFilterTest extends TestCase
{
    private const ALLOW = ['price' => ['gte', 'lte'], 'day' => ['gte', 'lte'], 'name' => ['eq']];

    #[Test]
    public function both_ends_of_a_range_parse_on_one_field(): void
    {
        $request = CollectionFilterRequest::fromQueryParam('price:gte:9;price:lte:20', self::ALLOW);

        self::assertSame([FilterOperator::Gte, FilterOperator::Lte], array_map(static fn ($t) => $t->operator, $request->terms));
        self::assertSame('price:gte:9;price:lte:20', $request->toQueryString());
    }

    #[Test]
    public function numbers_compare_as_numbers_and_days_as_text(): void
    {
        $rows = [['price' => 9, 'day' => '2026-09-30'], ['price' => 10, 'day' => '2026-10-01'], ['price' => 25.5, 'day' => '2026-10-31'], ['price' => null, 'day' => '2026-10-05']];
        $pick = static fn (string $filter): array => array_column(CollectionFilterRequest::fromQueryParam($filter, self::ALLOW)->apply($rows, static fn (array $row, string $field) => $row[$field]), 'day');

        self::assertSame(['2026-10-01', '2026-10-31'], $pick('price:gte:10'), '"9" is below "10" as a number, not above it as text; no price is no match');
        self::assertSame(['2026-09-30', '2026-10-01'], $pick('price:lte:10'));
        self::assertSame(['2026-10-01', '2026-10-05'], $pick('day:gte:2026-10-01;day:lte:2026-10-30'));
    }

    #[Test]
    public function large_integers_keep_their_precision(): void
    {
        $rows = [['price' => 9007199254740992, 'day' => 'a'], ['price' => 9007199254740993, 'day' => 'b']];
        $pick = static fn (string $filter): array => array_column(CollectionFilterRequest::fromQueryParam($filter, self::ALLOW)->apply($rows, static fn (array $row, string $field) => $row[$field]), 'day');

        self::assertSame(['b'], $pick('price:gte:9007199254740993'), '2^53 and 2^53 + 1 are the same float, not the same integer');
        self::assertSame(['a'], $pick('price:lte:9007199254740992'));
    }

    #[Test]
    public function a_range_is_only_where_it_is_allowed(): void
    {
        $this->expectException(InvalidFilterException::class);
        CollectionFilterRequest::fromQueryParam('name:gte:a', self::ALLOW);
    }
}
