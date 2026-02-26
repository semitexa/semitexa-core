<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

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
    private static ?self $instance = null;

    private array $layers = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
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
        return self::$instance;
    }

    public static function getOrFail(): self
    {
        return self::$instance ?? self::getInstance();
    }
}
