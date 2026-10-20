<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        /** Requests left in the window as it stands now. */
        public int $remaining,
        /** Seconds until one more request would be allowed; 0 when allowed. */
        public int $retryAfter,
    ) {}
}
