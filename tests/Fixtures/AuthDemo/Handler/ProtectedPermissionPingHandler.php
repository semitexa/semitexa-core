<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Handler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\ProtectedPermissionPingPayload;

#[AsPayloadHandler(payload: ProtectedPermissionPingPayload::class, resource: ResourceResponse::class)]
final class ProtectedPermissionPingHandler implements TypedHandlerInterface
{
    public function handle(ProtectedPermissionPingPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return PingResponder::respond($resource, ProtectedPermissionPingPayload::PATH);
    }
}
