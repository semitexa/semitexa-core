<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextInterface;

/**
 * A store built bare (`new DefaultTenantContextStore()`) must see the tenant
 * the active store resolved. semitexa/tenancy keeps it in the coroutine-local
 * slot named below; core read a slot of its own and answered 'default' for
 * every tenant.
 */
final class DefaultTenantContextStoreReadsTheActiveTenantTest extends TestCase
{
    /**
     * Written out, not taken from the constant: this is the key every
     * released semitexa/tenancy writes, and the two must not drift apart.
     */
    private const TENANCY_KEY = 'semitexa.tenancy.tenant_context';

    #[Test]
    public function it_reads_the_slot_the_tenancy_store_writes(): void
    {
        self::assertSame(self::TENANCY_KEY, DefaultTenantContextStore::CONTEXT_KEY);
    }

    #[Test]
    public function two_tenants_in_two_coroutines_each_see_their_own(): void
    {
        $seen = [];
        $hooks = \Swoole\Runtime::getHookFlags();
        try {
            \Swoole\Coroutine\run(static function () use (&$seen): void {
                foreach (['tenant-acme', 'tenant-globex'] as $tenantId) {
                    \Swoole\Coroutine::create(static function () use ($tenantId, &$seen): void {
                        // What semitexa/tenancy's store does on set().
                        CoroutineLocal::set(self::TENANCY_KEY, self::tenant($tenantId));
                        // Yield, so the other tenant's coroutine runs in between.
                        \Swoole\Coroutine::sleep(0.005);
                        $seen[$tenantId] = TenantContextAccess::tenantIdOrDefault(
                            (new DefaultTenantContextStore())->tryGet(),
                        );
                    });
                }
                \Swoole\Coroutine::create(static function () use (&$seen): void {
                    \Swoole\Coroutine::sleep(0.005);
                    $seen['none'] = (new DefaultTenantContextStore())->tryGet();
                });
            });
        } finally {
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        ksort($seen);
        self::assertSame(
            ['none' => null, 'tenant-acme' => 'tenant-acme', 'tenant-globex' => 'tenant-globex'],
            $seen,
        );
    }

    #[Test]
    public function what_it_sets_the_tenancy_slot_holds(): void
    {
        $held = null;
        $hooks = \Swoole\Runtime::getHookFlags();
        try {
            \Swoole\Coroutine\run(static function () use (&$held): void {
                $tenant = self::tenant('tenant-acme');
                (new DefaultTenantContextStore())->set($tenant);
                $held = CoroutineLocal::get(self::TENANCY_KEY) === $tenant;
            });
        } finally {
            \Swoole\Coroutine::set(['hook_flags' => $hooks ?: SWOOLE_HOOK_ALL]);
        }

        self::assertTrue($held);
    }

    private static function tenant(string $tenantId): TenantContextInterface
    {
        return new class ($tenantId) implements TenantContextInterface {
            public function __construct(private string $id) {}

            public function getTenantId(): string
            {
                return $this->id;
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        };
    }
}
