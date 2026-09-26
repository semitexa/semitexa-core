<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Payload;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPublicPayload(
    path: self::PATH,
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class PublicPingPayload
{
    public const PATH = '/core-fixture/auth/public';
}
