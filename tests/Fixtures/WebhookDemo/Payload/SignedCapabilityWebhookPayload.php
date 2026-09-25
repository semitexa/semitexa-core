<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo\Payload;

use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Authorization\Attribute\RequiresCapability;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Tests\Fixtures\WebhookDemo\WebhookDemoServiceCapability;
use Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver;

/** A signed receiver that also needs its service principal to hold a capability. */
#[AsServicePayload(
    path: self::PATH,
    methods: ['POST'],
    responseWith: ResourceResponse::class,
)]
#[AsWebhookReceiver(secretRef: SignedWebhookPayload::SECRET_REF, name: self::RECEIVER_KEY)]
#[RequiresCapability(WebhookDemoServiceCapability::AcceptSignedEvents)]
final class SignedCapabilityWebhookPayload
{
    public const PATH = '/core-fixture/webhook/signed-capability';
    public const RECEIVER_KEY = 'core-fixture-signed-capability';
}
