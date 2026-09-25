<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Handler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\ProtectedCapabilityPingPayload;

#[AsPayloadHandler(payload: ProtectedCapabilityPingPayload::class, resource: ResourceResponse::class)]
final class ProtectedCapabilityPingHandler implements TypedHandlerInterface
{
    public function handle(ProtectedCapabilityPingPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return PingResponder::respond($resource, ProtectedCapabilityPingPayload::PATH);
    }
}
