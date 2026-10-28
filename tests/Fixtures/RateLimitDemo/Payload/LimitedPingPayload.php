<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\RateLimitDemo\Payload;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\RateLimit;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPublicPayload(
    path: self::PATH,
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
#[RateLimit(2, 60)]
final class LimitedPingPayload
{
    public const PATH = '/core-fixture/rate-limit/ping';
}
