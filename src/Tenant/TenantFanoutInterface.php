<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

/**
 * Runs a unit of work once per known tenant, each under that tenant's context.
 *
 * The seam for BACKGROUND, context-less code (worker timers, cron ticks) that
 * must cover every tenant — a request already carries exactly one tenant, so
 * this is never used inside one. The core implementation ({@see SingleTenantFanout})
 * runs the work once under the 'default' tenant; the tenancy package overrides
 * it to iterate the real tenant registry, binding each tenant's context around
 * the callback so #[TenantScoped] reads/writes inside it are correctly scoped.
 *
 * One tenant's failure must not abort the rest — implementations isolate each
 * iteration.
 */
interface TenantFanoutInterface
{
    /**
     * @param \Closure(string $tenantId): void $work invoked once per tenant,
     *        under that tenant's bound context; receives the tenant id.
     */
    public function eachTenant(\Closure $work): void;
}
