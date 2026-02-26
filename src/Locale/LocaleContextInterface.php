<?php

declare(strict_types=1);

namespace Semitexa\Core\Locale;

interface LocaleContextInterface
{
    public function getLocale(): string;

    public function setLocale(string $locale): void;

    public static function get(): ?self;

    public static function getOrFail(): self;
}
