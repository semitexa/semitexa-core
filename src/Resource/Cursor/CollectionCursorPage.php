<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Cursor;

use Semitexa\Core\Resource\Exception\MalformedCollectionEnvelopeException;

/**
 * Cursor-mode pagination metadata. Sibling to
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
 * (which omits the `mode` field for byte-identical
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
     * Read back what {@see toArray()} wrote. Exact inverse; a round-trip test pins the two
     * directions together, because between them they are the only declaration of this shape.
     *
     * Unlike its offset sibling, `mode` is REQUIRED and must be the literal 'cursor' — it is
     * the discriminator {@see \Semitexa\Core\Resource\CollectionEnvelope} uses to decide
     * which of the two page types a response carries, so accepting anything else here would
     * let a mislabelled envelope through as a cursor page.
     *
     * @param array<string, mixed> $meta
     */
    public static function fromArray(array $meta): self
    {
        $mode = $meta['mode'] ?? throw MalformedCollectionEnvelopeException::missingKey('mode', 'meta.pagination');
        if ($mode !== 'cursor') {
            throw MalformedCollectionEnvelopeException::wrongType('mode', "the literal 'cursor'", $mode, 'meta.pagination');
        }

        return new self(
            perPage:    self::intAt($meta, 'perPage'),
            total:      self::intAt($meta, 'total'),
            hasNext:    self::boolAt($meta, 'hasNext'),
            nextCursor: self::nullableStringAt($meta, 'nextCursor'),
            cursor:     self::nullableStringAt($meta, 'cursor'),
        );
    }

    /** @param array<string, mixed> $meta */
    private static function intAt(array $meta, string $key): int
    {
        $value = $meta[$key] ?? throw MalformedCollectionEnvelopeException::missingKey($key, 'meta.pagination');
        if (!is_int($value)) {
            throw MalformedCollectionEnvelopeException::wrongType($key, 'an int', $value, 'meta.pagination');
        }

        return $value;
    }

    /** @param array<string, mixed> $meta */
    private static function boolAt(array $meta, string $key): bool
    {
        $value = $meta[$key] ?? throw MalformedCollectionEnvelopeException::missingKey($key, 'meta.pagination');
        if (!is_bool($value)) {
            throw MalformedCollectionEnvelopeException::wrongType($key, 'a bool', $value, 'meta.pagination');
        }

        return $value;
    }

    /**
     * Present-but-null is meaningful here and different from absent: `nextCursor: null`
     * states there is no next page, which is exactly what a client branches on.
     *
     * @param array<string, mixed> $meta
     */
    private static function nullableStringAt(array $meta, string $key): ?string
    {
        if (!array_key_exists($key, $meta)) {
            throw MalformedCollectionEnvelopeException::missingKey($key, 'meta.pagination');
        }
        $value = $meta[$key];
        if ($value !== null && !is_string($value)) {
            throw MalformedCollectionEnvelopeException::wrongType($key, 'a string or null', $value, 'meta.pagination');
        }

        return $value;
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
