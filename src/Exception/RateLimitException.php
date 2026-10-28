<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

class RateLimitException extends DomainException
{
    public function __construct(
        private readonly int $retryAfter = 60,
        /** The limit that was reached, when one is known; it adds the RateLimit-* headers. */
        private readonly ?int $limit = null,
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

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function getErrorContext(): array
    {
        return ['retry_after' => $this->retryAfter];
    }

    /**
     * What a 429 tells the client: when to try again, and with a known limit
     * the RateLimit-Limit / -Remaining / -Reset fields of the IETF RateLimit
     * header draft (no X- prefix).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = ['Retry-After' => (string) $this->retryAfter];
        if ($this->limit !== null) {
            $headers['RateLimit-Limit'] = (string) $this->limit;
            $headers['RateLimit-Remaining'] = '0';
            $headers['RateLimit-Reset'] = (string) $this->retryAfter;
        }

        return $headers;
    }
}
