<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant\Layer;

readonly class LocaleLayer implements TenantLayerInterface
{
    public function id(): string
    {
        return 'locale';
    }

    public function defaultValue(): TenantLayerValueInterface
    {
        return LocaleValue::default();
    }
}

readonly class LocaleValue implements TenantLayerValueInterface
{
    public function __construct(
        public string $code,
    ) {
        if (!preg_match('/^[a-z]{2,3}(-[A-Z]{2,4})?$/', $code)) {
            throw new \InvalidArgumentException("Invalid locale code: {$code}");
        }
    }

    public function layer(): TenantLayerInterface
    {
        return new LocaleLayer();
    }

    public function rawValue(): string
    {
        return $this->code;
    }

    public static function default(): self
    {
        return new self('en');
    }

    public static function fromCode(string $code): self
    {
        return new self(strtolower($code));
    }
}
