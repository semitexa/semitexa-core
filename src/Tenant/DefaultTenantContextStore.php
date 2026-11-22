<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\WorkerState;
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

    /**
     * Who answers outside a coroutine, when a tenant store keeps the tenant
     * of a CLI run somewhere of its own.
     *
     * @var (\Closure(): ?TenantContextInterface)|null
     */
    #[WorkerState('Named once per worker by the tenant store that owns the CLI tenant; it reads that store, and holds no tenant itself.')]
    private static ?\Closure $outsideCoroutine = null;

    /**
     * Name the reader of the tenant that is active outside a coroutine.
     *
     * semitexa/tenancy keeps the tenant of `tenant:run`, a queue job or a
     * scheduler run in a static. A copy of it in the slot went wrong twice:
     * CoroutineLocal::beginRequest() empties the slot while the tenant stays
     * active, and a reset run inside a coroutine cannot reach the copy. So a
     * store built here asks the owner instead of reading a copy.
     *
     * One reader per worker: naming a second replaces the first, and the one
     * replaced is returned so it can be put back. While the reader answers a
     * tenant, it is what tryGet() says outside a coroutine; this store's own
     * set() and clear() there only matter when the reader answers none.
     *
     * @param (\Closure(): ?TenantContextInterface)|null $reader null takes it away
     * @return (\Closure(): ?TenantContextInterface)|null the reader named before
     */
    public static function readOutsideCoroutineFrom(?\Closure $reader): ?\Closure
    {
        $previous = self::$outsideCoroutine;
        self::$outsideCoroutine = $reader;

        return $previous;
    }

    public function get(): TenantContextInterface
    {
        return $this->tryGet() ?? DefaultTenantContext::getInstance();
    }

    public function tryGet(): ?TenantContextInterface
    {
        if (self::$outsideCoroutine !== null && !self::inCoroutine()) {
            $active = (self::$outsideCoroutine)();
            if ($active !== null) {
                return $active;
            }
            // No tenant there: what this store's own set() put in the slot
            // outside a coroutine still answers.
        }

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

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }
}
