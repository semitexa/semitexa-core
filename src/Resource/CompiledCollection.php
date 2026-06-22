<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Pagination\CollectionPage;

/**
 * One Way Pattern: the result of compiling + executing a
 * {@see CollectionCriteria} against a source — the windowed items
 * plus exactly one resolved pagination shape.
 *
 * `items` are source-level objects (e.g. ORM resource models); the
 * handler projects them to API resource DTOs before rendering. The
 * page/cursorPage mutual exclusion mirrors
 * {@see \Semitexa\Core\Resource\JsonResourceResponse::withResources()}.
 */
final readonly class CompiledCollection
{
    /** @param list<object> $items */
    public function __construct(
        public array $items,
        public ?CollectionPage $page = null,
        public ?CollectionCursorPage $cursorPage = null,
    ) {
        if ($page !== null && $cursorPage !== null) {
            throw new \InvalidArgumentException(
                'CompiledCollection: page and cursorPage are mutually exclusive.',
            );
        }
    }

    /** The effective pagination mode the source answered in. */
    public function mode(): ?string
    {
        if ($this->cursorPage !== null) {
            return CollectionPaginationPolicy::MODE_CURSOR;
        }

        return $this->page?->mode ?? ($this->page !== null ? CollectionPaginationPolicy::MODE_PAGE : null);
    }
}
