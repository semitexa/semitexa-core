<?php

declare(strict_types=1);

namespace Semitexa\Core\Locale;

final class DefaultLocaleContext implements LocaleContextInterface
{
    private string $locale = 'en';

    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public static function get(): ?self
    {
        return self::getInstance();
    }

    public static function getOrFail(): self
    {
        return self::getInstance();
    }
}
