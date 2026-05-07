<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5c: thrown by the selection-set translator when the client
 * selects a field name that does not exist on the target Resource DTO,
 * or when a scalar field is given a sub-selection (e.g. `name { id }`),
 * or when a non-expandable relation is selected for embedding.
 * Maps to HTTP 400.
 */
final class UnknownGraphqlFieldException extends DomainException
{
    public function __construct(
        private readonly string $fieldPath,
        private readonly string $resourceType,
        private readonly string $reason = '',
    ) {
        parent::__construct(sprintf(
            'Unknown GraphQL selection "%s" on resource "%s"%s.',
            $fieldPath,
            $resourceType,
            $reason !== '' ? ': ' . $reason : '',
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'field'    => $this->fieldPath,
            'resource' => $this->resourceType,
            'reason'   => $this->reason,
        ];
    }
}
