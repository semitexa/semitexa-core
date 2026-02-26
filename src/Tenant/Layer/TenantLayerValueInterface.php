<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant\Layer;

interface TenantLayerValueInterface
{
    public function layer(): TenantLayerInterface;

    public function rawValue(): string;
}
