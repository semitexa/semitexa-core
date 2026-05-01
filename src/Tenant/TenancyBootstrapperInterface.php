<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\HttpResponse;
use Semitexa\Core\Request;

/**
 * Contract for the per-request tenancy bootstrapper. Replaces Core's concrete
 * dependency on {@see \Semitexa\Tenancy\Application\Service\TenancyBootstrapper} so any tenancy
 * integration (or a null implementation in tests) can participate in the
 * request lifecycle.
 *
 * The bootstrapper resolves the tenant for the request, writes it into the
 * active {@see TenantContextStoreInterface}, and optionally returns an early
 * response (for example a redirect when a tenant identifier is malformed).
 */
interface TenancyBootstrapperInterface
{
    public function isEnabled(): bool;

    /**
     * Resolve the tenant for the given request and install it into the
     * tenant context store. Return an early HTTP response to short-circuit
     * the pipeline (e.g. redirect to a canonical tenant host), or null to
     * continue normally.
     */
    public function resolve(Request $request): ?HttpResponse;
}
