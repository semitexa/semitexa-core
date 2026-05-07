<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Thrown when an `?include=…` token does not map to any expandable relation
 * on the rendered Resource DTO. Surfaces as HTTP 400.
 */
final class UnknownIncludeException extends DomainException
{
    public function __construct(
        private readonly string $token,
        private readonly string $resourceType,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
            'Unknown include token "%s" on resource "%s".',
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
