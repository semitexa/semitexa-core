<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Handler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\ServicePingPayload;

#[AsPayloadHandler(payload: ServicePingPayload::class, resource: ResourceResponse::class)]
final class ServicePingHandler implements TypedHandlerInterface
{
    public function handle(ServicePingPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return PingResponder::respond($resource, ServicePingPayload::PATH);
    }
}
