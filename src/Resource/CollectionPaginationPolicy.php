<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Resource\Pagination\CollectionPageRequest;

/**
 * One Way Pattern: the server-side pagination policy for one
 * collection route — the resolved value object behind semitexa-api's
 * `#[CollectionPaginated]` attribute.
 *
 * Pure value object: plain scalars only, so core stays free of api
 * vocabulary. Routes without a declared policy use {@see default()},
 * which mirrors the static {@see CollectionPageRequest} bounds with
 * plain page mode — i.e. exactly today's behavior.
 *
 * Modes:
 *   - `page`   — offset pagination, `?page=`/`?perPage=`.
 *   - `cursor` — keyset pagination, `?cursor=`/`?perPage=`.
 *   - `auto`   — server policy: answers in page mode (with total)
 *                while the post-filter total <= countThreshold, else
 *                in cursor mode. The response reports the effective
 *                mode in `meta.pagination.mode`.
 *   - `single` — no windowing; the whole (filtered) collection in one
 *                response with `meta.pagination.mode = "single"`.
 */
final readonly class CollectionPaginationPolicy
{
    public const MODE_PAGE   = 'page';
    public const MODE_CURSOR = 'cursor';
    public const MODE_AUTO   = 'auto';
    public const MODE_SINGLE = 'single';

    public const MODES = [self::MODE_PAGE, self::MODE_CURSOR, self::MODE_AUTO, self::MODE_SINGLE];

    /**
     * @param list<int> $perPageOptions advertised page sizes (contract only;
     *                                  any perPage within [1, maxPerPage] is
     *                                  still accepted on the wire)
     */
    public function __construct(
        public string $mode = self::MODE_PAGE,
        public int $defaultPerPage = CollectionPageRequest::DEFAULT_PER_PAGE,
        public array $perPageOptions = [],
        public int $maxPerPage = CollectionPageRequest::MAX_PER_PAGE,
        public int $countThreshold = 1000,
        public bool $declared = true,
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'CollectionPaginationPolicy: unknown mode "%s" (allowed: %s).',
                $mode,
                implode(', ', self::MODES),
            ));
        }
        if ($maxPerPage < 1) {
            throw new \InvalidArgumentException('CollectionPaginationPolicy: maxPerPage must be >= 1.');
        }
        if ($defaultPerPage < 1 || $defaultPerPage > $maxPerPage) {
            throw new \InvalidArgumentException(sprintf(
                'CollectionPaginationPolicy: defaultPerPage %d must be within [1, %d].',
                $defaultPerPage,
                $maxPerPage,
            ));
        }
        foreach ($perPageOptions as $option) {
            if (!is_int($option) || $option < 1 || $option > $maxPerPage) {
                throw new \InvalidArgumentException(sprintf(
                    'CollectionPaginationPolicy: perPageOption %s must be an integer within [1, %d].',
                    is_scalar($option) ? (string) $option : get_debug_type($option),
                    $maxPerPage,
                ));
            }
        }
        if ($countThreshold < 1) {
            throw new \InvalidArgumentException('CollectionPaginationPolicy: countThreshold must be >= 1.');
        }
    }

    /**
     * The undeclared-route policy: today's static bounds, plain page
     * mode, flagged `declared: false` so consumers know not to emit
     * the mode discriminator or per-route contract keys.
     */
    public static function default(): self
    {
        return new self(declared: false);
    }
}
