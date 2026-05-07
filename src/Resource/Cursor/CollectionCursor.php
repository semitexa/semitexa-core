<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Cursor;

/**
 * Phase 6l: opaque cursor payload, decoded.
 *
 * The cursor binds a deterministic position in a filtered+sorted
 * collection stream to a context (sort signature + filter
 * signature). A cursor produced for one `(sort, filter)` context
 * cannot be replayed against another — the decoder rejects with
 * HTTP 400.
 *
 *   - `version`         — bumped when the wire shape changes; v1
 *                         is the only supported version.
 *   - `sortSignature`   — `CollectionSortRequest::toQueryString()`
 *                         from the request that generated this
 *                         cursor.
 *   - `filterSignature` — `CollectionFilterRequest::toQueryString()`
 *                         likewise.
 *   - `lastSortKey`     — string-coerced values of the last visible
 *                         row, in the same field order as the user
 *                         sort terms (the implicit `id` tie-breaker
 *                         is recorded separately on `lastId`).
 *   - `lastId`          — the last visible row's id, as the
 *                         universal stable tie-breaker.
 *
 * Pure value object. No DB, ORM, HTTP, or framework state.
 */
final readonly class CollectionCursor
{
    public const CURRENT_VERSION = 1;

    /**
     * @param list<string> $lastSortKey one entry per user sort term, in declared order
     */
    public function __construct(
        public int $version,
        public string $sortSignature,
        public string $filterSignature,
        public array $lastSortKey,
        public string $lastId,
    ) {
    }
}
