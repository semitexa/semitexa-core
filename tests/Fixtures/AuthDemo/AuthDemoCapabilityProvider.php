<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Rbac\Domain\Contract\CapabilityProviderInterface;

#[SatisfiesServiceContract(of: CapabilityProviderInterface::class)]
final class AuthDemoCapabilityProvider implements CapabilityProviderInterface
{
    public function getCapabilitiesForUser(string $userId): array
    {
        return AuthDemoCapabilityStore::getForUser($userId);
    }
}
