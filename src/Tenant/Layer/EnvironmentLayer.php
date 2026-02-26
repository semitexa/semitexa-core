<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant\Layer;

readonly class EnvironmentLayer implements TenantLayerInterface
{
    public function id(): string
    {
        return 'environment';
    }

    public function defaultValue(): TenantLayerValueInterface
    {
        return EnvironmentValue::prod();
    }
}

readonly class EnvironmentValue implements TenantLayerValueInterface
{
    public function __construct(
        public string $value,
    ) {
        if (!in_array($value, ['dev', 'staging', 'prod'], true)) {
            throw new \InvalidArgumentException("Invalid environment: {$value}");
        }
    }

    public function layer(): TenantLayerInterface
    {
        return new EnvironmentLayer();
    }

    public function rawValue(): string
    {
        return $this->value;
    }

    public static function dev(): self
    {
        return new self('dev');
    }

    public static function staging(): self
    {
        return new self('staging');
    }

    public static function prod(): self
    {
        return new self('prod');
    }

    public static function default(): self
    {
        return self::prod();
    }

    public static function fromValue(string $value): self
    {
        return new self($value);
    }
}
