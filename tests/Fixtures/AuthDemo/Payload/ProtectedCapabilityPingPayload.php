<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Payload;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Authorization\Attribute\RequiresCapability;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\AuthDemo\RuntimeCapability;

#[AsProtectedPayload(
    path: self::PATH,
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
#[RequiresCapability(RuntimeCapability::Ping)]
final class ProtectedCapabilityPingPayload
{
    public const PATH = '/core-fixture/auth/protected-with-capability';
}
