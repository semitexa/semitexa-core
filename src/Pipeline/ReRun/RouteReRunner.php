<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline\ReRun;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

/**
 * Default {@see ReRunnerInterface} — re-establishes the per-tick execution context
 * from the immutable block + session reference, then re-runs the chain auth-first
 * via {@see RouteExecutor::reExecute()} (Track R · R2; design §B.2/§B.3).
 *
 * The two responsibilities here are exactly the "context re-establishment" half of
 * R2 (C2); the re-run mechanism itself (auth gate → fresh data resolution → frame |
 * TERMINATE) lives in {@see RouteExecutor::reExecute()} (C1):
 *
 *  1. **Tenant context** — re-installed into the request-scoped store from the
 *     immutable {@see ReRunContext::getTenantContext()} (the core-local equivalent
 *     of `TenantContext::fromQueuePayload()` — see {@see ReRunContext}). Never read
 *     from the cached DTO.
 *  2. **Request** — rebuilt from the connect-time snapshot
 *     ({@see ReRunContext::rebuildRequest()}); its session cookie is the identity
 *     source the re-auth resolves the live subject from each run.
 *
 * The Strategy-C pre-filter hook (design §B.4) is consulted here as a first-class
 * seam before the full re-run; it is unimplemented today (always null/empty), so
 * Strategy A (the full-chain re-run) is always the path taken.
 */
final class RouteReRunner implements ReRunnerInterface
{
    public function __construct(
        private readonly RouteExecutor $routeExecutor,
        private readonly ContainerInterface $container,
    ) {}

    public function reRun(ReRunContext $context, array $filterOverride = []): ReRunResult
    {
        // 1. Re-establish tenant context from the IMMUTABLE block (never the DTO).
        //    The re-run may execute in the SHARED transport coroutine (KISS
        //    unification), whose coroutine-local slot can hold a FOREIGN
        //    context from whatever request it served last — so the captured
        //    context is swapped in unconditionally and the previous one is
        //    restored afterwards. No-op when the stream ran without a captured
        //    context or no tenant store is bound (CLI / test / no-tenancy).
        $tenantContext = $context->getTenantContext();
        $swappedStore = null;
        $previousContext = null;
        if ($tenantContext !== null && $this->container->has(TenantContextStoreInterface::class)) {
            /** @var TenantContextStoreInterface $store */
            $store = $this->container->get(TenantContextStoreInterface::class);
            // SWAP, don't set-if-absent: the executing coroutine may hold a
            // FOREIGN context (the shared transport coroutine last served some
            // other tenant's request — observed live: a demo stream re-ran
            // under a leftover 'default' context). clear() drops the
            // immutability lock, so this also works when re-running inside
            // the request's own coroutine, where we swap the captured context
            // for itself.
            $previousContext = $store->tryGet();
            $store->clear();
            $store->set($tenantContext);
            $swappedStore = $store;
        }

        try {
            // 2. Strategy-C seam (design §B.4): a future in-memory pre-filter may
            //    short-circuit the full re-run when the changed rows can be diffed
            //    against the broadcast properties in memory. Unimplemented today — the
            //    hook always yields null/empty, so we fall through to Strategy A.
            $broadcastProperties = $context->getBroadcastProperties();
            if ($broadcastProperties !== null && $broadcastProperties !== []) {
                // Strategy C (future): in-memory diff / pre-filter here. Intentionally
                // not implemented — Strategy A (full-chain re-run) remains the default.
            }

            // 3. Rebuild the auth-bearing request from the connect-time snapshot and
            //    re-run the chain auth-first with the cached DTO (Strategy A). The
            //    view-change filter override (if any) is forwarded to reExecute, which
            //    applies it FILTER-ONLY (marker-gated) AFTER the auth gate — never here,
            //    so identity re-resolution cannot see it.
            $request = $context->rebuildRequest();

            return $this->routeExecutor->reExecute(
                $context->getRoute(),
                $request,
                $context->getCachedDto(),
                $filterOverride,
            );
        } finally {
            if ($swappedStore !== null) {
                $swappedStore->clear();
                if ($previousContext !== null) {
                    $swappedStore->set($previousContext);
                }
            }
        }
    }
}
