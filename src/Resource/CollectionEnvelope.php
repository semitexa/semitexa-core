<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Exception\MalformedCollectionEnvelopeException;
use Semitexa\Core\Resource\Pagination\CollectionPage;

/**
 * The reading half of the collection envelope {@see JsonResourceResponse} emits.
 *
 * The envelope has always had a producer and no reader. `JsonResourceResponse::withResources()`
 * builds `{data, meta.pagination, meta.filterOptions}` from typed values — `meta.pagination`
 * comes straight off a {@see CollectionPage} or {@see CollectionCursorPage} — and the type is
 * then destroyed at the wire. Everything on the other side did
 * `json_decode($body, true)` into `mixed` and indexed blindly:
 * `$decoded['meta']['pagination']['total']`.
 *
 * MEASURED before this existed: 247 offset-on-mixed PHPStan errors, 192 of them in
 * `src/modules/*\/tests` doing exactly that. Not 247 separate problems — one shape nobody
 * had named, multiplied by every place that read it.
 *
 * This lives in core, beside its producer, rather than in the testing package: core defines
 * the envelope, so core is the only place where emitting and parsing can be kept honest
 * against each other. Tests are the main consumer today, but a reader is not a test concern —
 * anything that consumes a Semitexa collection response needs the same type.
 *
 * ⚠️ This class must never change what goes ON the wire. It only reads back what
 * {@see JsonResourceResponse} already writes.
 */
final readonly class CollectionEnvelope
{
    /**
     * @param list<array<string, mixed>> $data
     * @param array<string, mixed>       $filterOptions
     */
    public function __construct(
        public array $data,
        public CollectionPage|CollectionCursorPage|null $pagination = null,
        public array $filterOptions = [],
    ) {
    }

    /**
     * Parse a raw JSON response body.
     *
     * @throws MalformedCollectionEnvelopeException when the body is not the envelope
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw MalformedCollectionEnvelopeException::notDecodable($e->getMessage());
        }

        if (!is_array($decoded)) {
            throw MalformedCollectionEnvelopeException::wrongType('<root>', 'an object', $decoded, 'the envelope');
        }

        return self::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $envelope
     *
     * @throws MalformedCollectionEnvelopeException
     */
    public static function fromArray(array $envelope): self
    {
        $data = $envelope['data'] ?? throw MalformedCollectionEnvelopeException::missingKey('data', 'the envelope');
        if (!is_array($data) || !array_is_list($data)) {
            throw MalformedCollectionEnvelopeException::wrongType('data', 'a list', $data, 'the envelope');
        }
        /** @var list<array<string, mixed>> $data */

        $meta = $envelope['meta'] ?? [];
        if (!is_array($meta)) {
            throw MalformedCollectionEnvelopeException::wrongType('meta', 'an object', $meta, 'the envelope');
        }

        $pagination = null;
        if (isset($meta['pagination'])) {
            $raw = $meta['pagination'];
            if (!is_array($raw)) {
                throw MalformedCollectionEnvelopeException::wrongType('pagination', 'an object', $raw, 'meta');
            }
            // The discriminator the two page types already agree on: cursor mode always
            // writes mode:'cursor', offset mode writes 'page' or omits the key entirely.
            $pagination = ($raw['mode'] ?? null) === 'cursor'
                ? CollectionCursorPage::fromArray($raw)
                : CollectionPage::fromArray($raw);
        }

        $filterOptions = $meta['filterOptions'] ?? [];
        if (!is_array($filterOptions)) {
            throw MalformedCollectionEnvelopeException::wrongType('filterOptions', 'an object', $filterOptions, 'meta');
        }

        return new self($data, $pagination, $filterOptions);
    }

    public function count(): int
    {
        return count($this->data);
    }

    /**
     * One item, by position.
     *
     * Exists so a caller stops writing `$decoded['data'][0]['id']`, which is three
     * unchecked reads on mixed and the single most common shape in the errors this class
     * was built to remove. Out-of-range is a thrown error, not a null to propagate.
     *
     * @return array<string, mixed>
     */
    public function item(int $index): array
    {
        return $this->data[$index]
            ?? throw new \OutOfRangeException(sprintf(
                'Collection envelope has no item at index %d (%d item(s) present).',
                $index,
                count($this->data),
            ));
    }

    /**
     * Offset pagination, or a thrown error explaining which mode the response actually used.
     *
     * A caller asking for the wrong mode has misunderstood the endpoint, and returning null
     * would push that misunderstanding one line further down.
     */
    public function page(): CollectionPage
    {
        if (!$this->pagination instanceof CollectionPage) {
            throw new \LogicException(sprintf(
                'This response carries %s, not offset pagination.',
                $this->pagination === null ? 'no pagination' : 'cursor pagination',
            ));
        }

        return $this->pagination;
    }

    /** Cursor pagination, or a thrown error naming the mode actually used. */
    public function cursor(): CollectionCursorPage
    {
        if (!$this->pagination instanceof CollectionCursorPage) {
            throw new \LogicException(sprintf(
                'This response carries %s, not cursor pagination.',
                $this->pagination === null ? 'no pagination' : 'offset pagination',
            ));
        }

        return $this->pagination;
    }
}
