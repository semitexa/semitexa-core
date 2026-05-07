<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5d: thrown when a GraphQL POST request carries an
 * application/json body but the parsed object has no `query` key, or
 * the value is null. Maps to HTTP 400.
 */
final class MissingGraphqlQueryException extends DomainException
{
    public function __construct(
        private readonly string $contentType = '',
    ) {
        parent::__construct(sprintf(
            'GraphQL request body is missing the required "query" field%s.',
            $contentType !== '' ? sprintf(' (Content-Type: %s)', $contentType) : '',
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'content_type' => $this->contentType,
        ];
    }
}
