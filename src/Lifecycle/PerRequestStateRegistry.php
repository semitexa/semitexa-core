<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

/**
 * Worker-scoped registry of per-request runtime state resetters.
 *
 * Caches that hold REQUEST-SCOPED, USER-SCOPED, or AUTH-DECISION state
 * register a clear() callback once at boot or first use; the framework
 * calls {@see resetAll()} after every request and queued message in a
 * finally block (see Application::handleRequest, queue worker drain loops).
 *
 * Examples of state that MUST register here:
 *   - Semitexa\Rbac\Application\Service\RbacDecisionCache (per-user grant cache)
 *   - any future per-request authorization-decision cache
 *   - any per-request principal/identity cache
 *
 * Examples of state that MUST NOT register here:
 *   - route discovery cache
 *   - reflection metadata cache
 *   - PayloadAccessPolicyResolver::clearCache() (immutable per-class metadata)
 *   - class discovery cache
 *   - static configuration / environment cache
 *
 * Resetting the second group on every request would defeat Swoole's
 * "build container once, serve N requests" performance model. The decision
 * "is this state request-scoped or worker-scoped" is what determines
 * registration; once registered, the framework owns the lifecycle.
 *
 * Reset callbacks are invoked under a try/catch so a misbehaving cache
 * never breaks the request lifecycle.
 */
final class PerRequestStateRegistry
{
    /** @var array<string, callable(): void> name → reset callback */
    private static array $resetters = [];

    /**
     * Register a per-request state resetter. Idempotent: re-registering the
     * same name replaces the previous callback (handy for tests and for
     * caches that re-register lazily on first use).
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
     * Invoke every registered resetter. Called by the framework at the end of
     * each request lifecycle in a finally block — so it runs even when the
     * request handler throws or the request returns 4xx/5xx.
     */
    public static function resetAll(): void
    {
        foreach (self::$resetters as $reset) {
            try {
                $reset();
            } catch (\Throwable) {
                // Never let a misbehaving cache reset break the request lifecycle.
                // The cache stays "dirty" for the next request — that is strictly
                // worse than the leak we just fixed in the happy path, but the
                // alternative (propagating the exception) breaks every subsequent
                // request handled by the same worker process.
            }
        }
    }

    /**
     * Test-only: drop every registered resetter. Useful for tests that need
     * deterministic registration ordering.
     */
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
