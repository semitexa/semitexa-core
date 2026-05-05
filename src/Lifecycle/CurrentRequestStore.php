<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Request;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * Per-request lookup for the active HTTP Request.
 *
 * PreHydrationAuthGate publishes the Request here before invoking the
 * AuthBootstrapper. Any AuthHandler that needs request data (header
 * lookup, signature verification) reads it from this store, with the
 * payload-side `getHttpRequest()` convention available as a per-payload
 * fallback for callers that want it. Reading from the store is the
 * canonical mechanism — payloads need no boilerplate.
 *
 * The store registers itself with PerRequestStateRegistry on first
 * set() so it's cleared at the end of every request, matching the
 * lifecycle of RbacDecisionCache and AuthContextStore.
 *
 * Coroutine-safe via CoroutineLocal — concurrent Swoole requests do
 * not see each other's Request even though the API is static.
 */
final class CurrentRequestStore
{
    private const KEY = '__semitexa_current_http_request';
    private const REGISTRY_NAME = 'current_http_request_store';

    private static bool $registered = false;

    public static function set(Request $request): void
    {
        self::ensureRegistered();
        CoroutineLocal::set(self::KEY, $request);
    }

    public static function get(): ?Request
    {
        $value = CoroutineLocal::get(self::KEY);
        return $value instanceof Request ? $value : null;
    }

    public static function clear(): void
    {
        CoroutineLocal::remove(self::KEY);
    }

    private static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        PerRequestStateRegistry::register(
            self::REGISTRY_NAME,
            static function (): void {
                self::clear();
            },
        );
    }
}
