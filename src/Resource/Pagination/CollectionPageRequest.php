<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Pagination;

use Semitexa\Core\Resource\Exception\InvalidPaginationException;

/**
 * Phase 6i: parsed-and-validated `?page=` / `?perPage=` request.
 *
 * Pure value object. No DB, ORM, HTTP, or framework state — just the
 * two integer parameters and a slicing helper. Parsing happens
 * exactly once via {@see fromQueryParams()}; thereafter the values
 * are immutable and trusted.
 *
 * Defaults:
 *   - page    = 1
 *   - perPage = 10
 *
 * Limits:
 *   - page    >= 1
 *   - perPage in [1, MAX_PER_PAGE]
 *
 * Invalid input throws {@see InvalidPaginationException} (HTTP 400).
 */
final readonly class CollectionPageRequest
{
    public const DEFAULT_PAGE    = 1;
    public const DEFAULT_PER_PAGE = 10;
    public const MAX_PER_PAGE    = 50;

    public function __construct(
        public int $page,
        public int $perPage,
    ) {
        // Defensive: the constructor is public for the test factory
        // path, so re-validate here. Production code uses
        // `fromQueryParams()`.
        self::guard('page',    (string) $page,    $page);
        self::guard('perPage', (string) $perPage, $perPage);
    }

    /**
     * Parse the raw `?page=` and `?perPage=` query string values. Both
     * inputs are nullable — when omitted the documented defaults
     * apply. Empty string is treated as omitted (matches PHP's
     * convention for missing-but-set query keys).
     *
     * Throws {@see InvalidPaginationException} for any non-integer
     * value or out-of-range integer.
     */
    public static function fromQueryParams(?string $rawPage, ?string $rawPerPage): self
    {
        $page    = self::parseOrDefault('page',    $rawPage,    self::DEFAULT_PAGE);
        $perPage = self::parseOrDefault('perPage', $rawPerPage, self::DEFAULT_PER_PAGE);

        return new self($page, $perPage);
    }

    /**
     * Repo-friendly slicing: zero-based offset of the first item on
     * the requested page.
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Slice a list of items deterministically. The pipeline calls
     * this **before** `expandMany()` so resolvers only see the paged
     * slice, never the full catalog.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function slice(array $items): array
    {
        $offset = $this->offset();
        $slice  = array_slice($items, $offset, $this->perPage);
        // array_slice preserves list-ness when input is a list — the
        // declared template covers it, but a defensive `array_values`
        // protects against callers that hand us associative arrays.
        return array_values($slice);
    }

    private static function parseOrDefault(string $name, ?string $raw, int $default): int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        // Strict integer parsing: reject `"1.5"`, `"abc"`, `"1abc"`,
        // and signed values that don't round-trip via (int) cast.
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new InvalidPaginationException($name, $raw, 'must be an integer');
        }
        $value = (int) $raw;
        self::guard($name, $raw, $value);
        return $value;
    }

    private static function guard(string $name, string $raw, int $value): void
    {
        if ($name === 'page' && $value < 1) {
            throw new InvalidPaginationException($name, $raw, 'must be >= 1');
        }
        if ($name === 'perPage') {
            if ($value < 1) {
                throw new InvalidPaginationException($name, $raw, 'must be >= 1');
            }
            if ($value > self::MAX_PER_PAGE) {
                throw new InvalidPaginationException(
                    $name,
                    $raw,
                    sprintf('must be <= %d', self::MAX_PER_PAGE),
                );
            }
        }
    }
}
