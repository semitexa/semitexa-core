<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant\Layer;

readonly class ThemeLayer implements TenantLayerInterface
{
    public function id(): string
    {
        return 'theme';
    }

    public function defaultValue(): TenantLayerValueInterface
    {
        return new ThemeValue('default');
    }
}
