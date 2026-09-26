<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Rbac\Domain\Contract\PermissionProviderInterface;

#[SatisfiesServiceContract(of: PermissionProviderInterface::class)]
final class AuthDemoPermissionProvider implements PermissionProviderInterface
{
    public function getPermissionsForUser(string $userId): array
    {
        return AuthDemoPermissionStore::getForUser($userId);
    }
}
