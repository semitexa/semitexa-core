<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant\Layer;

readonly class ThemeValue implements TenantLayerValueInterface
{
    public function __construct(
        public string $theme,
    ) {}

    public function layer(): TenantLayerInterface
    {
        return new ThemeLayer();
    }

    public function rawValue(): string
    {
        return $this->theme;
    }

    public static function default(): self
    {
        return new self('default');
    }

    public static function fromName(string $name): self
    {
        return new self($name);
    }
}
