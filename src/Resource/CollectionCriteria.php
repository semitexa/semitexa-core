<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;
use Semitexa\Core\Resource\Sort\CollectionSortRequest;

/**
 * One Way Pattern: the compiled, validated intent of one collection
 * read — page + sort + filter + free-text search, plus the route's
 * pagination policy — ready for a {@see \Semitexa\Core\Contract\CollectionQueryCompilerInterface}
 * implementation to push down to the source (SQL via the ORM
 * compiler) or for the in-memory `apply()` helpers on the wrapped
 * request objects (non-ORM sources).
 *
 * Every member is already validated: the request VOs throw their
 * typed 400 exceptions at parse time, so a constructed criteria is
 * trusted. Pure value object — no DB, ORM, HTTP, or framework state.
 */
final readonly class CollectionCriteria
{
    /**
     * @param ?string      $q            trimmed free-text search term; null = no search
     * @param list<string> $searchFields fields `q` covers (from `#[CollectionSearchable]`);
     *                                   empty when the route declares no search
     * @param ?string      $cursor       raw opaque cursor token; null = not a cursor request
     * @param bool         $pageWasRequested whether `?page=` was explicitly present —
     *                                   `auto` mode needs to distinguish an explicit
     *                                   page request from the parser default
     */
    public function __construct(
        public CollectionPageRequest $page,
        public CollectionSortRequest $sort,
        public CollectionFilterRequest $filter,
        public ?string $q,
        public array $searchFields,
        public ?string $cursor,
        public CollectionPaginationPolicy $policy,
        public bool $pageWasRequested = false,
    ) {
    }

    public function isCursorRequest(): bool
    {
        return $this->cursor !== null;
    }

    public function hasSearch(): bool
    {
        return $this->q !== null && $this->searchFields !== [];
    }
}
