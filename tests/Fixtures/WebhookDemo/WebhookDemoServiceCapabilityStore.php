<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo;

use Semitexa\Authorization\Domain\Contract\CapabilityInterface;
use Semitexa\Core\Lifecycle\TestStateResetRegistry;

/** Process-local capability grants, per service id. Test-only state. */
final class WebhookDemoServiceCapabilityStore
{
    public const REGISTRY_NAME = 'core_fixture_webhook_service_capability_store';

    /** @var array<string, list<CapabilityInterface>> */
    private static array $grants = [];

    /** @param list<CapabilityInterface> $capabilities */
    public static function setForService(string $serviceId, array $capabilities): void
    {
        self::ensureRegistered();
        self::$grants[$serviceId] = array_values($capabilities);
    }

    /** @return list<CapabilityInterface> */
    public static function getForService(string $serviceId): array
    {
        return self::$grants[$serviceId] ?? [];
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
