<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 5d: thrown when a GraphQL POST request body carries a
 * Content-Type the bridge does not support (anything other than
 * `application/json` or `application/graphql`), or when the JSON body
 * declares features Phase 5d's bounded transport rejects (non-empty
 * `variables`). Maps to HTTP 400.
 */
final class UnsupportedGraphqlRequestBodyException extends DomainException
{
    public function __construct(
        private readonly string $reason,
        private readonly string $contentType = '',
    ) {
        parent::__construct(sprintf(
            'Unsupported GraphQL request body: %s%s.',
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
