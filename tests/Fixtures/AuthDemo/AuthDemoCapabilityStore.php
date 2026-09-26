<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo;

use Semitexa\Authorization\Domain\Contract\CapabilityInterface;
use Semitexa\Core\Lifecycle\TestStateResetRegistry;

/** Process-local capability grants, per user id. Test-only state, like the permission store. */
final class AuthDemoCapabilityStore
{
    public const REGISTRY_NAME = 'core_fixture_auth_capability_store';

    /** @var array<string, list<CapabilityInterface>> */
    private static array $grants = [];

    /** @param list<CapabilityInterface> $capabilities */
    public static function setForUser(string $userId, array $capabilities): void
    {
        self::ensureRegistered();
        self::$grants[$userId] = array_values($capabilities);
    }

    /** @return list<CapabilityInterface> */
    public static function getForUser(string $userId): array
    {
        return self::$grants[$userId] ?? [];
    }

    public static function clear(): void
    {
        self::ensureRegistered();
        self::$grants = [];
    }

    private static function ensureRegistered(): void
    {
        if (!TestStateResetRegistry::isRegistered(self::REGISTRY_NAME)) {
            TestStateResetRegistry::register(self::REGISTRY_NAME, static function (): void {
                self::$grants = [];
            });
        }
    }
}
