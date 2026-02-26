<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;

interface TenantContextInterface
{
    public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface;

    public function hasLayer(TenantLayerInterface $layer): bool;

    public static function get(): ?self;

    public static function getOrFail(): self;
}
