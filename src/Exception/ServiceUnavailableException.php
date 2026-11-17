<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\CarriesResponseHeadersInterface;
use Semitexa\Core\Http\HttpStatus;

/**
 * A request the app cannot serve right now but can a moment later: a 503 with
 * Retry-After, so the client retries instead of taking a refusal for an answer.
 * Thrown when something the request needs is down, e.g. the session store
 * (tk-post-during-redis-outage-fails-csrf-not-retryable).
 */
final class ServiceUnavailableException extends DomainException implements CarriesResponseHeadersInterface
{
    public function __construct(
        string $message = 'Temporarily unavailable, try again in a moment.',
        private readonly int $retryAfter = 1,
    ) {
        if ($retryAfter < 0) {
            throw new \InvalidArgumentException('$retryAfter must be non-negative.');
        }
        parent::__construct($message);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::ServiceUnavailable;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function getErrorContext(): array
    {
        return ['retry_after' => $this->retryAfter];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['Retry-After' => (string) $this->retryAfter];
    }
}
