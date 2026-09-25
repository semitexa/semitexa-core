<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo\Payload;

use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver;

/** Authenticated by an HMAC signature over the body, and by nothing else. */
#[AsServicePayload(
    path: self::PATH,
    methods: ['POST'],
    responseWith: ResourceResponse::class,
)]
#[AsWebhookReceiver(secretRef: self::SECRET_REF)]
final class SignedWebhookPayload
{
    public const PATH = '/core-fixture/webhook/signed';
    public const SECRET_REF = 'env:CORE_FIXTURE_WEBHOOK_SECRET';
}
