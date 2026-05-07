<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Sort;

/**
 * Phase 6j: one resolved `(field, direction)` pair from a parsed
 * `?sort=` request. Pure value object — the parser produces a list
 * of these and the apply step iterates them in declared order.
 */
final readonly class SortTerm
{
    public function __construct(
        public string $field,
        public SortDirection $direction,
    ) {
    }
}
