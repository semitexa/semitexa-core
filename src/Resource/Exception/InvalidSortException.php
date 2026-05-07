<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6j: thrown when the `?sort=` query parameter fails parsing
 * or validation. Covers:
 *
 *   - empty term in a comma list (`?sort=name,,id`),
 *   - leading double-minus (`?sort=--name`),
 *   - nested / dotted field names (`?sort=profile.bio`),
 *   - field names that are not in the route's explicit allowlist,
 *   - duplicate fields in a single sort spec.
 *
 * Maps to HTTP 400. The client can fix the request by trimming the
 * sort string; this is not a server bug.
 */
final class InvalidSortException extends DomainException
{
    /**
     * @param list<string> $allowedFields the canonical allowlist for
     *                                    this route, surfaced to the
     *                                    client in the error envelope
     *                                    so they can self-correct.
     */
    public function __construct(
        public readonly string $rawValue,
        public readonly string $reason,
        public readonly array $allowedFields = [],
    ) {
        parent::__construct(sprintf(
            'Invalid sort parameter "sort"=%s: %s.',
            $rawValue === '' ? '<empty>' : '"' . $rawValue . '"',
            $reason,
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'parameter'      => 'sort',
            'value'          => $this->rawValue,
            'reason'         => $this->reason,
            'allowed_fields' => $this->allowedFields,
        ];
    }
}
