<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant\Layer;

interface TenantLayerInterface
{
    public function id(): string;

    public function defaultValue(): TenantLayerValueInterface;
}
