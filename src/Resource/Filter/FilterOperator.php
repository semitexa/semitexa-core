<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Filter;

/**
 * The bounded set of filter operators supported by the
 * collection slice. Adding a new operator means three things must
 * land together: a case here, a parse/normalise rule in
 * {@see CollectionFilterRequest::parseTerm()}, and a comparison
 * branch in {@see FilterTerm::matches()}.
 *
 *   - `eq`       — exact equality after string normalisation.
 *   - `in`       — value membership in a non-empty comma-separated list.
 *   - `contains` — case-insensitive substring match (string fields only).
 *   - `gte`      — at least the value (a range's lower end): numbers compare
 *                  as numbers, anything else as text — ISO dates and moments
 *                  ("2026-10-06", "2026-10-06 09:30:00") sort correctly as text.
 *   - `lte`      — at most the value (a range's upper end).
 *
 * No regex, no strict `gt/lt`, no `startsWith/endsWith`.
 */
enum FilterOperator: string
{
    case Eq       = 'eq';
    case In       = 'in';
    case Contains = 'contains';
    case Gte      = 'gte';
    case Lte      = 'lte';

    /**
     * @return list<string> wire forms in declared order
     */
    public static function wireForms(): array
    {
        return ['eq', 'in', 'contains', 'gte', 'lte'];
    }
}
