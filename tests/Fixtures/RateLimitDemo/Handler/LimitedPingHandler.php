<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\RateLimitDemo\Handler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\RateLimitDemo\Payload\LimitedPingPayload;

#[AsPayloadHandler(payload: LimitedPingPayload::class, resource: ResourceResponse::class)]
final class LimitedPingHandler implements TypedHandlerInterface
{
    public function handle(LimitedPingPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setHeader('Content-Type', 'application/json')
            ->setContent('{"pong":true}');
    }
}
