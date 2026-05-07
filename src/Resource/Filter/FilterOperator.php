<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Filter;

/**
 * Phase 6k: the bounded set of filter operators supported by the
 * collection slice. Adding a new operator means three things must
 * land together: a case here, a parse/normalise rule in
 * {@see CollectionFilterRequest::parseTerm()}, and a comparison
 * branch in {@see FilterTerm::matches()}.
 *
 *   - `eq`       — exact equality after string normalisation.
 *   - `in`       — value membership in a non-empty comma-separated list.
 *   - `contains` — case-insensitive substring match (string fields only).
 *
 * No regex, no numeric ranges, no `gt/lt/gte/lte`, no
 * `startsWith/endsWith`. Wider operators are out of scope for the
 * baseline.
 */
enum FilterOperator: string
{
    case Eq       = 'eq';
    case In       = 'in';
    case Contains = 'contains';

    /**
     * @return list<string> wire forms in declared order
     */
    public static function wireForms(): array
    {
        return ['eq', 'in', 'contains'];
    }
}
