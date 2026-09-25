<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo;

use Semitexa\Core\Lifecycle\TestStateResetRegistry;

/**
 * The side effects a webhook receiver produced. Per-worker state that must
 * outlive the request that wrote it, so it registers with
 * TestStateResetRegistry and never with PerRequestStateRegistry.
 */
final class WebhookDemoEventStore
{
    public const REGISTRY_NAME = 'core_fixture_webhook_event_store';

    /** @var list<WebhookDemoEvent> */
    private static array $events = [];

    public static function record(WebhookDemoEvent $event): void
    {
        self::ensureRegistered();
        self::$events[] = $event;
    }

    public static function count(): int
    {
        return count(self::$events);
    }

    public static function clear(): void
    {
        self::ensureRegistered();
        self::$events = [];
    }

    private static function ensureRegistered(): void
    {
        if (!TestStateResetRegistry::isRegistered(self::REGISTRY_NAME)) {
            TestStateResetRegistry::register(self::REGISTRY_NAME, static function (): void {
                self::$events = [];
            });
        }
    }
}
