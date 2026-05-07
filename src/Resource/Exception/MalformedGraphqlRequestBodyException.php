<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5d: thrown when a GraphQL POST request carries a body that
 * cannot be parsed (malformed JSON, empty body declared as JSON, etc.).
 * Maps to HTTP 400.
 */
final class MalformedGraphqlRequestBodyException extends DomainException
{
    public function __construct(
        private readonly string $reason,
        private readonly string $contentType = '',
    ) {
        parent::__construct(sprintf(
            'Malformed GraphQL request body: %s%s',
            $reason,
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
            'reason'       => $this->reason,
            'content_type' => $this->contentType,
        ];
    }
}
