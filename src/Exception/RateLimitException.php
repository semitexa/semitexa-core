<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

class RateLimitException extends DomainException
{
    public function __construct(
        private readonly int $retryAfter = 60,
    ) {
        if ($retryAfter < 0) {
            throw new \InvalidArgumentException('$retryAfter must be non-negative.');
        }
        parent::__construct('Too many requests.');
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::TooManyRequests;
    }

    public function getErrorContext(): array
    {
        return ['retry_after' => $this->retryAfter];
    }
}
