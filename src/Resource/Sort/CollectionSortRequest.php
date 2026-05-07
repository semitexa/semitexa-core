<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Sort;

use Semitexa\Core\Resource\Exception\InvalidSortException;

/**
 * Phase 6j: parsed-and-validated `?sort=` request.
 *
 * Pure value object. No DB, ORM, HTTP, or framework state — just a
 * list of resolved {@see SortTerm}s and a deterministic
 * `apply(list, extractor)` helper. Parsing happens exactly once via
 * {@see fromQueryParam()}; thereafter the values are immutable and
 * trusted.
 *
 * Syntax (Phase 6j):
 *
 *   sort=name              ascending by `name`
 *   sort=-name             descending by `name`
 *   sort=name,id           ascending by name, ties broken by id
 *   sort=-name,id          descending by name, ascending by id
 *
 * Bounded by an **explicit allowlist** supplied by the caller; any
 * field outside the allowlist (including dotted relation paths) is
 * rejected with `InvalidSortException` (HTTP 400). Duplicate fields
 * in a single spec are also rejected — the syntax does not have a
 * deterministic "last-wins" rule, and silently dropping later terms
 * is a footgun.
 *
 * Default order: an empty / omitted parameter yields an empty term
 * list, and {@see apply()} then returns the input list unchanged.
 * This preserves the Phase 6i / 6h byte-identical no-sort default.
 */
final readonly class CollectionSortRequest
{
    /**
     * @param list<SortTerm> $terms parsed in left-to-right order
     */
    public function __construct(public array $terms)
    {
    }

    /**
     * Parse the raw `?sort=` query string against an explicit field
     * allowlist. Empty / null input yields a no-op request that
     * preserves the input order; any deviation from the bounded
     * grammar throws {@see InvalidSortException}.
     *
     * @param list<string> $allowedFields canonical, lower-case-friendly
     *                                    field names; the parser does a
     *                                    strict `in_array(..., true)`
     *                                    membership check.
     */
    public static function fromQueryParam(?string $raw, array $allowedFields): self
    {
        if ($raw === null) {
            return new self([]);
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return new self([]);
        }

        $parts = explode(',', $trimmed);
        $terms = [];
        $seen  = [];

        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '') {
                throw new InvalidSortException(
                    rawValue: $raw,
                    reason:   'empty term in comma-separated sort list',
                    allowedFields: $allowedFields,
                );
            }

            $direction = SortDirection::Asc;
            if (str_starts_with($token, '-')) {
                $direction = SortDirection::Desc;
                $token = substr($token, 1);
            }
            if ($token === '') {
                throw new InvalidSortException(
                    rawValue: $raw,
                    reason:   'sort field name is empty after direction prefix',
                    allowedFields: $allowedFields,
                );
            }
            if (str_starts_with($token, '-')) {
                throw new InvalidSortException(
                    rawValue: $raw,
                    reason:   'leading double minus is not a valid direction prefix',
                    allowedFields: $allowedFields,
                );
            }
            if (str_contains($token, '.')) {
                throw new InvalidSortException(
                    rawValue: $raw,
                    reason:   sprintf('nested / relation field "%s" is not allowed in this phase', $token),
                    allowedFields: $allowedFields,
                );
            }
            if (!in_array($token, $allowedFields, true)) {
                throw new InvalidSortException(
                    rawValue: $raw,
                    reason:   sprintf('field "%s" is not in the route allowlist', $token),
                    allowedFields: $allowedFields,
                );
            }
            if (isset($seen[$token])) {
                throw new InvalidSortException(
                    rawValue: $raw,
                    reason:   sprintf('field "%s" appears more than once', $token),
                    allowedFields: $allowedFields,
                );
            }
            $seen[$token] = true;
            $terms[] = new SortTerm($token, $direction);
        }

        return new self($terms);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * Deterministic in-memory sort. The `$extractor` callable maps an
     * item plus a field name to a comparable scalar. The VO does NOT
     * know the shape of the items it sorts — callers may hand it raw
     * catalog rows or Resource DTOs.
     *
     * Stable: items that compare equal under every term keep their
     * input order (PHP 8's `usort` is stable).
     *
     * @template T
     * @param list<T>                $items
     * @param callable(T, string):mixed $extractor
     * @return list<T>
     */
    public function apply(array $items, callable $extractor): array
    {
        if ($this->isEmpty() || count($items) <= 1) {
            return array_values($items);
        }
        $sorted = array_values($items);
        usort($sorted, function ($a, $b) use ($extractor): int {
            foreach ($this->terms as $term) {
                $va = $extractor($a, $term->field);
                $vb = $extractor($b, $term->field);
                $cmp = $va <=> $vb;
                if ($cmp !== 0) {
                    return $term->direction === SortDirection::Desc ? -$cmp : $cmp;
                }
            }
            return 0;
        });
        return $sorted;
    }

    /**
     * Render the parsed terms as the canonical wire syntax — used for
     * trace logs and OpenAPI examples. Round-trips through
     * {@see fromQueryParam()}.
     */
    public function toQueryString(): string
    {
        $out = [];
        foreach ($this->terms as $term) {
            $out[] = $term->direction === SortDirection::Desc
                ? '-' . $term->field
                : $term->field;
        }
        return implode(',', $out);
    }
}
