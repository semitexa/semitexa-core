<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Filter;

use Semitexa\Core\Resource\Exception\InvalidFilterException;

/**
 * Phase 6k: parsed-and-validated `?filter=` request.
 *
 * Pure value object. No DB, ORM, HTTP, or framework state — just a
 * list of resolved {@see FilterTerm}s plus a deterministic
 * `apply(list, extractor)` helper. Parsing happens exactly once via
 * {@see fromQueryParam()}; thereafter the values are immutable.
 *
 * **Wire syntax (Phase 6k).**
 *
 *     filter=field:operator:value
 *
 * Multiple terms are **semicolon-separated** in a single `?filter=`
 * parameter. PHP's default query parsing collapses repeated `?key=`
 * pairs to last-wins, so a single param with internal separators is
 * the deterministic shape. Example:
 *
 *     ?filter=id:in:1,2,3;name:contains:acme
 *
 * Equivalent semantics: `id IN [1,2,3] AND name CONTAINS "acme"`.
 *
 * Operator specifics:
 *
 *   - `eq`       — strict string equality after extracting the
 *                  scalar's canonical string form.
 *   - `in`       — value matches any element of a non-empty
 *                  comma-separated list.
 *   - `contains` — case-insensitive substring match (`stripos`).
 *
 * **Bounded by an explicit allowlist** (`field => list<operator>`)
 * supplied by the caller; any field outside the allowlist or any
 * `(field, operator)` pair the allowlist does not permit is
 * rejected with {@see InvalidFilterException} (HTTP 400). Duplicate
 * `(field, operator)` pairs in a single spec are also rejected.
 *
 * Default behaviour: an empty / omitted parameter yields an empty
 * term list and {@see apply()} returns the input unchanged. This
 * preserves the Phase 6j byte-identical no-filter default.
 */
final readonly class CollectionFilterRequest
{
    /**
     * @param list<FilterTerm> $terms parsed in left-to-right order
     */
    public function __construct(public array $terms)
    {
    }

    /**
     * Parse the raw `?filter=` value against an explicit allowlist.
     *
     * @param array<string, list<string>> $allowedFilters field → list of
     *                                                    operator wire forms.
     */
    public static function fromQueryParam(?string $raw, array $allowedFilters): self
    {
        if ($raw === null) {
            return new self([]);
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return new self([]);
        }

        $rawForReporting = $raw;

        $segments = explode(';', $trimmed);
        $terms    = [];
        $seen     = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                throw new InvalidFilterException(
                    rawValue: $rawForReporting,
                    reason:   'empty term in semicolon-separated filter list',
                    allowedFilters: $allowedFilters,
                );
            }

            $term = self::parseTerm($segment, $rawForReporting, $allowedFilters);

            $key = $term->field . '|' . $term->operator->value;
            if (isset($seen[$key])) {
                throw new InvalidFilterException(
                    rawValue: $rawForReporting,
                    reason:   sprintf(
                        '(field, operator) pair "%s:%s" appears more than once',
                        $term->field,
                        $term->operator->value,
                    ),
                    allowedFilters: $allowedFilters,
                );
            }
            $seen[$key] = true;
            $terms[] = $term;
        }

        return new self($terms);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * Filter a list of items deterministically. Items must satisfy
     * **every** term (AND semantics) to pass. The caller's extractor
     * maps `(item, field)` to a comparable scalar; the term's match
     * implementation lives on {@see FilterTerm}.
     *
     * Empty filter request → input list returned unchanged.
     *
     * @template T
     * @param list<T>                    $items
     * @param callable(T, string):mixed  $extractor
     * @return list<T>
     */
    public function apply(array $items, callable $extractor): array
    {
        if ($this->isEmpty()) {
            return array_values($items);
        }
        $out = [];
        foreach ($items as $item) {
            foreach ($this->terms as $term) {
                if (!$term->matches($item, $extractor)) {
                    continue 2;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Render the parsed terms as the canonical wire syntax — used for
     * trace logs and OpenAPI examples. Round-trips through
     * {@see fromQueryParam()} when handed the original allowlist.
     */
    public function toQueryString(): string
    {
        $segments = [];
        foreach ($this->terms as $term) {
            $value = is_array($term->value) ? implode(',', $term->value) : $term->value;
            $segments[] = $term->field . ':' . $term->operator->value . ':' . $value;
        }
        return implode(';', $segments);
    }

    /**
     * @param array<string, list<string>> $allowedFilters
     */
    private static function parseTerm(string $segment, string $raw, array $allowedFilters): FilterTerm
    {
        // Split on the first two `:` boundaries so the value is free
        // to contain colons (e.g. an IPv6 fragment in the future).
        // Three pieces required: field, operator, value.
        $firstColon = strpos($segment, ':');
        if ($firstColon === false) {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf('term "%s" is missing operator separator ":"', $segment),
                allowedFilters: $allowedFilters,
            );
        }
        $secondColon = strpos($segment, ':', $firstColon + 1);
        if ($secondColon === false) {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf('term "%s" is missing value separator ":"', $segment),
                allowedFilters: $allowedFilters,
            );
        }

        $field    = substr($segment, 0, $firstColon);
        $operator = substr($segment, $firstColon + 1, $secondColon - $firstColon - 1);
        $value    = substr($segment, $secondColon + 1);

        if ($field === '') {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   'filter field name is empty',
                allowedFilters: $allowedFilters,
            );
        }
        if ($operator === '') {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf('term "%s" has empty operator', $segment),
                allowedFilters: $allowedFilters,
            );
        }
        if ($value === '') {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf('term "%s" has empty value', $segment),
                allowedFilters: $allowedFilters,
            );
        }
        if (str_contains($field, '.')) {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf('nested / relation field "%s" is not allowed in this phase', $field),
                allowedFilters: $allowedFilters,
            );
        }
        if (!array_key_exists($field, $allowedFilters)) {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf('field "%s" is not in the route filter allowlist', $field),
                allowedFilters: $allowedFilters,
            );
        }

        $allowedOps = $allowedFilters[$field];
        if (!in_array($operator, $allowedOps, true)) {
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf(
                    'operator "%s" is not allowed on field "%s" (allowed: %s)',
                    $operator,
                    $field,
                    implode(', ', $allowedOps),
                ),
                allowedFilters: $allowedFilters,
            );
        }

        $operatorEnum = FilterOperator::tryFrom($operator);
        if ($operatorEnum === null) {
            // Unknown operator names are rejected by the allowlist
            // step above, but defend against an allowlist that lists
            // an operator the framework does not implement.
            throw new InvalidFilterException(
                rawValue: $raw,
                reason:   sprintf('operator "%s" is not implemented by the framework', $operator),
                allowedFilters: $allowedFilters,
            );
        }

        if ($operatorEnum === FilterOperator::In) {
            $items = explode(',', $value);
            $cleaned = [];
            foreach ($items as $candidate) {
                if ($candidate === '') {
                    throw new InvalidFilterException(
                        rawValue: $raw,
                        reason:   sprintf('empty entry in "in" list for field "%s"', $field),
                        allowedFilters: $allowedFilters,
                    );
                }
                $cleaned[] = $candidate;
            }
            if ($cleaned === []) {
                throw new InvalidFilterException(
                    rawValue: $raw,
                    reason:   sprintf('"in" list for field "%s" is empty', $field),
                    allowedFilters: $allowedFilters,
                );
            }
            return new FilterTerm($field, FilterOperator::In, $cleaned);
        }

        return new FilterTerm($field, $operatorEnum, $value);
    }
}
