<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

/**
 * Request-scoped store for the active {@see TenantContextInterface}.
 *
 * A single implementation is expected per process (contributed by whichever
 * tenancy integration is active). The store is responsible for coroutine
 * isolation and CLI fallback — callers get and set the context through the
 * same instance regardless of runtime.
 *
 * Reads through {@see get()} return the guest/default context when no tenant
 * has been resolved; callers that truly need a concrete tenant should check
 * {@see tryGet()} and handle null explicitly.
 */
interface TenantContextStoreInterface
{
    /**
     * Current tenant context for this request/coroutine.
     *
     * Implementations MAY return a "default" guest context rather than null
     * so downstream consumers never have to null-check. Implementations MUST
     * NOT share state across coroutines.
     */
    public function get(): TenantContextInterface;

    /**
     * Current tenant context, or null when nothing has been resolved yet.
     * Prefer {@see get()} when a default/guest context is acceptable.
     */
    public function tryGet(): ?TenantContextInterface;

    /**
     * Install the active tenant context for this request/coroutine. Called by
     * tenancy resolvers after identifying the tenant from the HTTP request or
     * CLI arguments.
     */
    public function set(TenantContextInterface $context): void;

    /**
     * Remove the active tenant context for this request/coroutine. Does not
     * affect other coroutines. Intended for CLI teardown and test cleanup.
     */
    public function clear(): void;
}
