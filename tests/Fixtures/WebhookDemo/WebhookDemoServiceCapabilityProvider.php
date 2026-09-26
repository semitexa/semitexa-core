<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Rbac\Domain\Contract\ServiceCapabilityProviderInterface;

#[SatisfiesServiceContract(of: ServiceCapabilityProviderInterface::class)]
final class WebhookDemoServiceCapabilityProvider implements ServiceCapabilityProviderInterface
{
    public function getCapabilitiesForService(string $serviceId, ?string $tenantId = null): array
    {
        return WebhookDemoServiceCapabilityStore::getForService($serviceId);
    }
}
