<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Core\Tenant\Layer\OrganizationValue;
use Semitexa\Core\Tenant\Layer\LocaleLayer;
use Semitexa\Core\Tenant\Layer\LocaleValue;
use Semitexa\Core\Tenant\Layer\EnvironmentLayer;
use Semitexa\Core\Tenant\Layer\EnvironmentValue;

final class DefaultTenantContext implements TenantContextInterface
{
    private const CTX_KEY = '__core_default_tenant_context';

    /** @worker-scoped CLI/non-coroutine fallback only. */
    private static ?self $instance = null;

    private array $layers = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() >= 0) {
            $existing = CoroutineLocal::get(self::CTX_KEY);
            if ($existing instanceof self) {
                return $existing;
            }

            $context = new self();
            CoroutineLocal::set(self::CTX_KEY, $context);

            return $context;
        }

        return self::$instance ??= new self();
    }

    public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
    {
        $id = $layer->id();
        return $this->layers[$id] ?? $layer->defaultValue();
    }

    public function hasLayer(TenantLayerInterface $layer): bool
    {
        return isset($this->layers[$layer->id()]);
    }

    public function setLayer(TenantLayerInterface $layer, TenantLayerValueInterface $value): void
    {
        $this->layers[$layer->id()] = $value;
    }

    public function setLayers(TenantLayerValueInterface ...$layers): void
    {
        foreach ($layers as $layer) {
            $this->layers[$layer->layer()->id()] = $layer;
        }
    }

    public static function get(): ?self
    {
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() >= 0) {
            $context = CoroutineLocal::get(self::CTX_KEY);

            return $context instanceof self ? $context : null;
        }

        return self::$instance;
    }

    public static function getOrFail(): self
    {
        return self::get() ?? self::getInstance();
    }
}
