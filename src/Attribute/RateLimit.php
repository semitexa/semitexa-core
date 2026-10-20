<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;
use Semitexa\Core\RateLimit\RateLimitKey;

/**
 * At most $limit requests per $perSeconds to this payload's route, counted per
 * client address or per signed-in user. One over answers 429 with Retry-After.
 *
 * Repeatable: `#[RateLimit(10, 60)] #[RateLimit(100, 3600)]` holds both.
 * The window slides (the previous window's count fades out as the current one
 * fills), so a burst at a window's edge cannot pass twice the limit.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RateLimit
{
    public function __construct(
        public int $limit,
        public int $perSeconds = 60,
        public RateLimitKey $by = RateLimitKey::Ip,
        /** Requests counted together; defaults to the payload class, so two routes never share one by accident. */
        public ?string $bucket = null,
    ) {
        if ($limit < 1 || $perSeconds < 1) {
            throw new \InvalidArgumentException('#[RateLimit] needs a limit and a window of at least 1.');
        }
    }
}
