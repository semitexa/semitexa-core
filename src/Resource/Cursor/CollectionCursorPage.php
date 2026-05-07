<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Cursor;

/**
 * Phase 6l: cursor-mode pagination metadata. Sibling to
 * {@see \Semitexa\Core\Resource\Pagination\CollectionPage} (offset
 * mode); the two are mutually exclusive — a response carries one or
 * the other, never both.
 *
 * Wire shape (rendered from {@see toArray()}):
 *
 *   {
 *     "mode":       "cursor",
 *     "perPage":    <int>,
 *     "total":      <int>,                      // post-filter total
 *     "hasNext":    <bool>,
 *     "nextCursor": <string|null>,
 *     "cursor":     <string|null>               // input echo
 *   }
 *
 * `mode: "cursor"` distinguishes this shape from offset pagination
 * (which omits the `mode` field for byte-identical Phase 6i
 * compatibility).
 *
 * `total` is included for in-memory collections where the count is
 * free; documented as "post-filter total". A DB-backed cursor
 * implementation may omit `total` to skip a `COUNT(*)` round-trip —
 * the field is still useful when cheap.
 *
 * `nextCursor` is `null` when there is no next page; clients can
 * branch on it without scanning the response body.
 *
 * `cursor` echoes the input cursor (or `null` on the first page)
 * so clients can confirm round-tripping.
 */
final readonly class CollectionCursorPage
{
    public function __construct(
        public int $perPage,
        public int $total,
        public bool $hasNext,
        public ?string $nextCursor,
        public ?string $cursor,
    ) {
    }

    /**
     * @return array{
     *   mode: 'cursor',
     *   perPage: int,
     *   total: int,
     *   hasNext: bool,
     *   nextCursor: string|null,
     *   cursor: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'mode'       => 'cursor',
            'perPage'    => $this->perPage,
            'total'      => $this->total,
            'hasNext'    => $this->hasNext,
            'nextCursor' => $this->nextCursor,
            'cursor'     => $this->cursor,
        ];
    }
}
