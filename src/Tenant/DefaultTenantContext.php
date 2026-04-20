<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;

final class DefaultTenantContext implements TenantContextInterface
{
    private array $layers = [];

    public function __construct()
    {
    }

    public static function getInstance(): self
    {
        return new self();
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

    public function getTenantId(): string
    {
        $org = $this->getLayer(new OrganizationLayer());

        return $org !== null ? $org->rawValue() : 'default';
    }

    public function isDefault(): bool
    {
        return $this->getTenantId() === 'default';
    }

    public static function get(): ?self
    {
        return null;
    }

    public static function getOrFail(): self
    {
        return self::getInstance();
    }
}
