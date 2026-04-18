<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Request;

/**
 * Resolves the active tenant context for one request.
 *
 * Implemented by tenancy packages so Core can depend on a stable contract
 * without importing concrete resolver chains.
 */
interface TenantResolverInterface
{
    public function resolve(Request $request): TenantContextInterface;
}
