<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Payload;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsProtectedPayload(
    path: self::PATH,
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ProtectedPingPayload
{
    public const PATH = '/core-fixture/auth/protected';
}
