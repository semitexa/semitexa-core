<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo\Handler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Tests\Fixtures\WebhookDemo\Payload\SignedWebhookPayload;
use Semitexa\Core\Tests\Fixtures\WebhookDemo\WebhookDemoEvent;
use Semitexa\Core\Tests\Fixtures\WebhookDemo\WebhookDemoEventStore;

#[AsPayloadHandler(payload: SignedWebhookPayload::class, resource: ResourceResponse::class)]
final class SignedWebhookHandler implements TypedHandlerInterface
{
    public function handle(SignedWebhookPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $request = CurrentRequestStore::get();
        WebhookDemoEventStore::record(new WebhookDemoEvent(
            (string) $request?->getHeader('X-Webhook-Event-Id'),
            (string) $request?->getHeader('X-Webhook-Event-Type'),
            [],
        ));

        return $resource
            ->setHeader('Content-Type', 'application/json')
            ->setContent((string) json_encode(['accepted' => true]));
    }
}
