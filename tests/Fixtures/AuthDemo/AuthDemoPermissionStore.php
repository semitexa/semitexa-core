<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo;

use Semitexa\Core\Lifecycle\TestStateResetRegistry;

/**
 * Process-local permission grants, per user id. Test-only state: it registers
 * with TestStateResetRegistry (never PerRequestStateRegistry), so grants
 * survive a request and are wiped only when a test asks.
 */
final class AuthDemoPermissionStore
{
    public const REGISTRY_NAME = 'core_fixture_auth_permission_store';

    /** @var array<string, list<string>> */
    private static array $grants = [];

    /** @param list<string> $permissions */
    public static function setForUser(string $userId, array $permissions): void
    {
        self::ensureRegistered();
        self::$grants[$userId] = array_values($permissions);
    }

    /** @return list<string> */
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
