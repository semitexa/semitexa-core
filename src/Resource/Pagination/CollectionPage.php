<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Pagination;

/**
 * Phase 6i: resolved pagination metadata attached to a collection
 * response envelope. Computed from a {@see CollectionPageRequest}
 * and the total item count.
 *
 * Conventions:
 *   - `total` is the count of the **full underlying collection**, not
 *     the count of items on this page. Clients use it to compute
 *     remaining-pages indicators independently of the renderer.
 *   - `pageCount` is `ceil(total / perPage)` when `total > 0`, and
 *     `0` for empty collections.
 *   - `hasNext` is `page < pageCount`. For empty collections it is
 *     always `false`.
 *   - `hasPrevious` is `page > 1`. It does not depend on `total`.
 *
 * Pure value object. The renderer reads it as a deterministic
 * `array<string, scalar>` envelope; the framework does not hide page
 * tokens or cursor strings inside this shape.
 */
final readonly class CollectionPage
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public int $pageCount,
        public bool $hasNext,
        public bool $hasPrevious,
    ) {
    }

    /**
     * Compute pagination metadata from a parsed request and a known
     * total. The total comes from the source-of-truth count of the
     * collection (e.g. the in-memory catalog's row count); slicing
     * happens elsewhere.
     */
    public static function compute(CollectionPageRequest $request, int $total): self
    {
        $total     = max(0, $total);
        $pageCount = $total > 0 ? (int) ceil($total / $request->perPage) : 0;

        return new self(
            page:        $request->page,
            perPage:     $request->perPage,
            total:       $total,
            pageCount:   $pageCount,
            hasNext:     $request->page < $pageCount,
            hasPrevious: $request->page > 1,
        );
    }

    /**
     * Render as the deterministic envelope shape carried inside
     * `meta.pagination` of a collection response.
     *
     * @return array{
     *   page: int,
     *   perPage: int,
     *   total: int,
     *   pageCount: int,
     *   hasNext: bool,
     *   hasPrevious: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'page'        => $this->page,
            'perPage'     => $this->perPage,
            'total'       => $this->total,
            'pageCount'   => $this->pageCount,
            'hasNext'     => $this->hasNext,
            'hasPrevious' => $this->hasPrevious,
        ];
    }
}
