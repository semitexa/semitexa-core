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
 */
#[SatisfiesServiceContract(of: TenantContextStoreInterface::class)]
final class DefaultTenantContextStore implements TenantContextStoreInterface
{
    private const CONTEXT_KEY = 'semitexa.core.default_tenant_context_store';

    private ?TenantContextInterface $fallback = null;

    public function get(): TenantContextInterface
    {
        return $this->tryGet() ?? DefaultTenantContext::getInstance();
    }

    public function tryGet(): ?TenantContextInterface
    {
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() >= 0) {
            $context = CoroutineLocal::get(self::CONTEXT_KEY);

            return $context instanceof TenantContextInterface ? $context : null;
        }

        return $this->fallback;
    }

    public function set(TenantContextInterface $context): void
    {
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() >= 0) {
            CoroutineLocal::set(self::CONTEXT_KEY, $context);

            return;
        }

        $this->fallback = $context;
    }

    public function clear(): void
    {
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() >= 0) {
            CoroutineLocal::remove(self::CONTEXT_KEY);

            return;
        }

        $this->fallback = null;
    }
}
