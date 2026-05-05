<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

/**
 * Worker-scoped registry of test-only / dev-only / admin reset hooks.
 *
 * Distinct from {@see PerRequestStateRegistry} on purpose: the per-request
 * registry is invoked by the framework after EVERY request and queued
 * message; this registry is invoked ONLY by tests, dev tools, or
 * explicit admin/diagnostic commands. The framework never calls
 * {@see resetAllForTesting()} from request lifecycle hooks.
 *
 * Use it for state that:
 *   - is intentionally per-WORKER (must outlive a single request) but
 *     needs deterministic reset between independent test runs, or
 *   - is test/demo-only state that production code never touches.
 *
 * Examples that SHOULD register here:
 *   - Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore
 *     (per-worker replay set; tests reset between runs)
 *   - Semitexa\Modules\AuthDemo\Application\Service\AuthDemoPermissionStore
 *   - Semitexa\Modules\AuthDemo\Application\Service\AuthDemoCapabilityStore
 *   - Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoEventStore
 *   - Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoServiceCapabilityStore
 *
 * Examples that MUST NOT register here:
 *   - Per-request state — that already self-registers with
 *     PerRequestStateRegistry and clears every request.
 *   - Persistent stores backed by a database — tests reset those via
 *     transaction rollback or schema teardown, not in-process.
 *   - Immutable metadata caches (HandlerReflectionCache, AttributeDiscovery
 *     classmap, PayloadAccessPolicyResolver) — they are derived from the
 *     codebase, not from request data.
 *
 * The method name is deliberately {@see resetAllForTesting()} — a
 * code-search for "resetAll" returns the per-request hook only, so a
 * confused caller cannot accidentally invoke this from production code.
 */
final class TestStateResetRegistry
{
    /** @var array<string, callable(): void> name → reset callback */
    private static array $resetters = [];

    /**
     * Register a test/dev/admin reset hook. Idempotent: re-registering the
     * same name replaces the previous callback.
     *
     * @param callable(): void $reset
     */
    public static function register(string $name, callable $reset): void
    {
        self::$resetters[$name] = $reset;
    }

    public static function isRegistered(string $name): bool
    {
        return isset(self::$resetters[$name]);
    }

    /**
     * Invoke every registered resetter. Tests call this in setUp/tearDown
     * to start from a known-empty state; dev/admin tools call it from
     * explicit reset commands. The framework NEVER calls this from
     * request or queue lifecycle.
     *
     * Reset callbacks run under try/catch — a misbehaving callback never
     * blocks the rest of the test reset chain.
     */
    public static function resetAllForTesting(): void
    {
        foreach (self::$resetters as $reset) {
            try {
                $reset();
            } catch (\Throwable) {
                // Swallow: the next reset must still run so a single
                // misbehaving demo store cannot poison test isolation.
            }
        }
    }

    /** Drop every registered resetter. Tests use this to verify registration ordering. */
    public static function unregisterAll(): void
    {
        self::$resetters = [];
    }

    /** @return list<string> registered resetter names — for diagnostics */
    public static function registeredNames(): array
    {
        return array_keys(self::$resetters);
    }
}
