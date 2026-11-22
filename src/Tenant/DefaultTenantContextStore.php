<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * Core fallback store used when no tenancy package overrides the contract.
 *
 * This keeps tenant access instance-based while still supporting coroutine
 * isolation and non-HTTP fallback flows.
 *
 * It is also what code built outside the container reaches for
 * (`new DefaultTenantContextStore()`), so it must read the tenant wherever the
 * active store put it: see {@see self::CONTEXT_KEY}.
 */
#[SatisfiesServiceContract(of: TenantContextStoreInterface::class)]
final class DefaultTenantContextStore implements TenantContextStoreInterface
{
    /**
     * The one coroutine-local slot the current tenant lives in.
     *
     * semitexa/tenancy's store writes this same key. Core used to keep a key
     * of its own, so with tenancy installed an instance built here read an
     * empty slot and every caller resolved the 'default' tenant while a real
     * one was active: one tenant's rows served to another. The value is
     * tenancy's, not a new one, so every released tenancy agrees with it.
     */
    public const CONTEXT_KEY = 'semitexa.tenancy.tenant_context';

    public function get(): TenantContextInterface
    {
        return $this->tryGet() ?? DefaultTenantContext::getInstance();
    }

    public function tryGet(): ?TenantContextInterface
    {
        $context = CoroutineLocal::get(self::CONTEXT_KEY);

        return $context instanceof TenantContextInterface ? $context : null;
    }

    public function set(TenantContextInterface $context): void
    {
        CoroutineLocal::set(self::CONTEXT_KEY, $context);
    }

    public function clear(): void
    {
        CoroutineLocal::remove(self::CONTEXT_KEY);
    }
}
