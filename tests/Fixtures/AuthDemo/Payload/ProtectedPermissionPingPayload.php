<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Payload;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Authorization\Attribute\RequiresPermission;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsProtectedPayload(
    path: self::PATH,
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
#[RequiresPermission(self::PERMISSION_SLUG)]
final class ProtectedPermissionPingPayload
{
    public const PATH = '/core-fixture/auth/protected-with-permission';
    public const PERMISSION_SLUG = 'core-fixture.runtime.ping';
}
