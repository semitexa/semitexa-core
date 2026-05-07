<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5d: thrown when a GraphQL POST request body contains a `query`
 * field that is present but invalid (non-string, empty after trim).
 * Maps to HTTP 400.
 */
final class InvalidGraphqlQueryException extends DomainException
{
    public function __construct(
        private readonly string $reason,
        private readonly string $actualType = '',
    ) {
        parent::__construct(sprintf(
            'Invalid GraphQL "query" field: %s%s.',
            $reason,
            $actualType !== '' ? sprintf(' (got %s)', $actualType) : '',
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'reason'      => $this->reason,
            'actual_type' => $this->actualType,
        ];
    }
}
