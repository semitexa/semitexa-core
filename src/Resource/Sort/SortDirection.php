<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Sort;

/**
 * Phase 6j: ascending vs. descending sort. The `?sort=` parser maps
 * a leading `-` to {@see self::Desc}; bare field names map to
 * {@see self::Asc}. The two values are the only possibilities — a
 * future expansion (NULLS FIRST / NULLS LAST) would land here, not
 * in the parser.
 */
enum SortDirection: string
{
    case Asc  = 'asc';
    case Desc = 'desc';
}
