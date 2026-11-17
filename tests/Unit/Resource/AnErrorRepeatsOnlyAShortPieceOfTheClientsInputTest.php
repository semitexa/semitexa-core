<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\EchoedValue;
use Semitexa\Core\Resource\Exception\InvalidFilterException;
use Semitexa\Core\Resource\Exception\InvalidPaginationException;
use Semitexa\Core\Resource\Exception\InvalidSortException;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;
use Semitexa\Core\Resource\Sort\CollectionSortRequest;

/**
 * tk-invalid-filter-echoes-the-raw-value (reported by the Guard project): a 400
 * for a rejected ?filter= repeated the client's whole query value, in the message
 * and in the error context, however long it was; ?sort= and ?page= did the same.
 * The response now repeats at most 64 characters of it; the exception keeps the
 * whole value for the server.
 */
final class AnErrorRepeatsOnlyAShortPieceOfTheClientsInputTest extends TestCase
{
    #[Test]
    public function a_rejected_filter_repeats_64_characters_of_it(): void
    {
        $raw = str_repeat('a', 5000) . ':eq:1';
        $shown = str_repeat('a', 64) . '…';

        $e = $this->filterError($raw);

        self::assertSame('Invalid filter parameter "filter"="' . $shown . '": field "' . $shown . '" is not in the route filter allowlist.', $e->getMessage());
        self::assertSame($shown, $e->getErrorContext()['value']);
        self::assertSame($raw, $e->rawValue, 'the server still has all of it');
    }

    #[Test]
    public function a_rejected_sort_repeats_64_characters_of_it(): void
    {
        $shown = str_repeat('b', 64) . '…';

        try {
            CollectionSortRequest::fromQueryParam(str_repeat('b', 5000), ['id', 'name']);
            self::fail('a sort on an unknown field is refused');
        } catch (InvalidSortException $e) {
            self::assertSame('Invalid sort parameter "sort"="' . $shown . '": field "' . $shown . '" is not in the route allowlist.', $e->getMessage());
            self::assertSame($shown, $e->getErrorContext()['value']);
        }
    }

    /**
     * Also found here: a number past a double's range (some 309 digits) came out of
     * (int) as 0, so a huge page or perPage was refused as "must be >= 1".
     *
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function hugePages(): iterable
    {
        $huge = str_repeat('9', 5000);
        $shown = str_repeat('9', 64) . '…';
        yield 'page' => [$huge, null, 'Invalid pagination parameter "page"="' . $shown . '": is too large.'];
        yield 'perPage' => [null, $huge, 'Invalid pagination parameter "perPage"="' . $shown . '": must be <= 100.'];
        yield 'a negative page' => ['-' . $huge, null, 'Invalid pagination parameter "page"="-' . str_repeat('9', 63) . '…": must be >= 1.'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('hugePages')]
    public function a_rejected_page_repeats_64_characters_of_it_and_says_why(?string $page, ?string $perPage, string $message): void
    {
        try {
            CollectionPageRequest::fromQueryParams($page, $perPage, defaultPerPage: 10, maxPerPage: 100);
            self::fail('a page past the integer range is refused');
        } catch (InvalidPaginationException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame(mb_substr($e->rawValue, 0, 64) . '…', $e->getErrorContext()['value']);
        }
    }

    #[Test]
    public function the_cut_never_splits_a_character(): void
    {
        $shown = str_repeat('é', 64) . '…';

        $e = $this->filterError(str_repeat('é', 100) . ':eq:1');

        self::assertSame('Invalid filter parameter "filter"="' . $shown . '": field "' . $shown . '" is not in the route filter allowlist.', $e->getMessage());
        self::assertTrue(mb_check_encoding($e->getMessage(), 'UTF-8'));
    }

    #[Test]
    public function up_to_64_characters_are_repeated_as_they_came(): void
    {
        self::assertSame(str_repeat('c', 64), EchoedValue::of(str_repeat('c', 64)));
        self::assertSame(str_repeat('c', 64) . '…', EchoedValue::of(str_repeat('c', 65)));
        self::assertSame('Invalid filter parameter "filter"="email:eq:x": field "email" is not in the route filter allowlist.', $this->filterError('email:eq:x')->getMessage());
    }

    private function filterError(string $raw): InvalidFilterException
    {
        try {
            CollectionFilterRequest::fromQueryParam($raw, ['id' => ['eq', 'in'], 'name' => ['eq']]);
        } catch (InvalidFilterException $e) {
            return $e;
        }
        self::fail('a filter on an unknown field is refused');
    }
}
