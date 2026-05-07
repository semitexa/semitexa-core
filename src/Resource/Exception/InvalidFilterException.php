<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6k: thrown when the `?filter=` query parameter fails parsing
 * or validation. Covers:
 *
 *   - malformed expression (`?filter=bad`, `?filter=name:eq`,
 *     `?filter=name:eq:Acme:extra`),
 *   - empty term in a semicolon list (`?filter=name:eq:Acme;;id:eq:1`),
 *   - empty value (`?filter=id:eq:`),
 *   - unsupported operator (`?filter=id:contains:1`),
 *   - field outside the route's allowlist (`?filter=email:eq:x`),
 *   - nested / dotted field (`?filter=profile.bio:eq:x`),
 *   - empty `in` list (`?filter=id:in:`),
 *   - duplicate `(field, operator)` pair in a single filter spec.
 *
 * Maps to HTTP 400. The client can fix the request by trimming the
 * filter expression; this is not a server bug. The exception carries
 * the route's allowlist in the error context so clients can
 * self-correct.
 */
final class InvalidFilterException extends DomainException
{
    /**
     * @param array<string, list<string>> $allowedFilters field → list of allowed operators
     */
    public function __construct(
        public readonly string $rawValue,
        public readonly string $reason,
        public readonly array $allowedFilters = [],
    ) {
        parent::__construct(sprintf(
            'Invalid filter parameter "filter"=%s: %s.',
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
            'parameter'        => 'filter',
            'value'            => $this->rawValue,
            'reason'           => $this->reason,
            'allowed_filters'  => $this->allowedFilters,
        ];
    }
}
