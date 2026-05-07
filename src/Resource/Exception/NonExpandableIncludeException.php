<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Thrown when an `?include=…` token names a real relation that has not been
 * declared `expandable: true`. Surfaces as HTTP 400.
 */
final class NonExpandableIncludeException extends DomainException
{
    public function __construct(
        private readonly string $token,
        private readonly string $resourceType,
    ) {
        parent::__construct(sprintf(
            'Include token "%s" on resource "%s" is not expandable.',
            $token,
            $resourceType,
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'token'    => $this->token,
            'resource' => $this->resourceType,
        ];
    }
}
