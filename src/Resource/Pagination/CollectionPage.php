<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Pagination;

/**
 * Resolved pagination metadata attached to a collection
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
    /**
     * One Way Pattern: `$mode` is the explicit pagination-mode
     * discriminator (`meta.pagination.mode`) for routes with a declared
     * `#[CollectionPaginated]` policy. `null` omits the key entirely,
     * keeping the envelope byte-identical for every route
     * that does not declare a policy.
     */
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public int $pageCount,
        public bool $hasNext,
        public bool $hasPrevious,
        public ?string $mode = null,
    ) {
    }

    /**
     * Compute pagination metadata from a parsed request and a known
     * total. The total comes from the source-of-truth count of the
     * collection (e.g. the in-memory catalog's row count); slicing
     * happens elsewhere.
     */
    public static function compute(CollectionPageRequest $request, int $total, ?string $mode = null): self
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
            mode:        $mode,
        );
    }

    /**
     * Render as the deterministic envelope shape carried inside
     * `meta.pagination` of a collection response.
     *
     * @return array{
     *   mode?: string,
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
        $out = [];
        if ($this->mode !== null) {
            // Mirrors CollectionCursorPage::toArray(), where `mode`
            // leads the envelope. Key absent when no policy declared.
            $out['mode'] = $this->mode;
        }
        $out['page']        = $this->page;
        $out['perPage']     = $this->perPage;
        $out['total']       = $this->total;
        $out['pageCount']   = $this->pageCount;
        $out['hasNext']     = $this->hasNext;
        $out['hasPrevious'] = $this->hasPrevious;

        return $out;
    }
}
