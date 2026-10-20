<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\SignedUrlDemo\Handler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\SignedUrlDemo\Payload\SignedDownloadPayload;

#[AsPayloadHandler(payload: SignedDownloadPayload::class, resource: ResourceResponse::class)]
final class SignedDownloadHandler implements TypedHandlerInterface
{
    public function handle(SignedDownloadPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setHeader('Content-Type', 'application/json')
            ->setContent('{"file":true}');
    }
}
