<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Handler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\PublicPingPayload;

#[AsPayloadHandler(payload: PublicPingPayload::class, resource: ResourceResponse::class)]
final class PublicPingHandler implements TypedHandlerInterface
{
    public function handle(PublicPingPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return PingResponder::respond($resource, PublicPingPayload::PATH);
    }
}
