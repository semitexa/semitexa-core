<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo;

use Semitexa\Authorization\Domain\Contract\CapabilityInterface;

enum WebhookDemoServiceCapability: string implements CapabilityInterface
{
    case AcceptSignedEvents = 'core-fixture.webhook.accept-signed-events';
}
