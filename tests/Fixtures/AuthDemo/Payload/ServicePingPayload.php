<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Payload;

use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsServicePayload(
    path: self::PATH,
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ServicePingPayload
{
    public const PATH = '/core-fixture/auth/service';
}
