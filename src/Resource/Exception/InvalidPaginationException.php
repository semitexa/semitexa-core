<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6i: thrown when a `?page=` / `?perPage=` query parameter
 * fails parsing or validation. Covers:
 *
 *   - non-integer values (e.g. `?page=abc`),
 *   - zero or negative values (`?page=0`, `?perPage=-1`),
 *   - `perPage` above the configured maximum.
 *
 * Maps to HTTP 400. The client can fix the request by adjusting the
 * pagination query parameters; this is not a server bug.
 */
final class InvalidPaginationException extends DomainException
{
    public function __construct(
        public readonly string $parameter,
        public readonly string $rawValue,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Invalid pagination parameter "%s"=%s: %s.',
            $parameter,
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
            'parameter' => $this->parameter,
            'value'     => $this->rawValue,
            'reason'    => $this->reason,
        ];
    }
}
