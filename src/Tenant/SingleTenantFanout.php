<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Attribute\SatisfiesServiceContract;

/**
 * Default {@see TenantFanoutInterface} for a single-tenant deployment (no
 * tenancy package installed): runs the work once under the 'default' sentinel
 * — the same id {@see TenantContextAccess::tenantIdOrDefault} yields for the
 * no-context case, so stores stay consistent. The tenancy package ships a
 * higher-priority implementation that iterates the real tenant registry.
 */
#[SatisfiesServiceContract(of: TenantFanoutInterface::class)]
final class SingleTenantFanout implements TenantFanoutInterface
{
    public function eachTenant(\Closure $work): void
    {
        $work('default');
    }
}
