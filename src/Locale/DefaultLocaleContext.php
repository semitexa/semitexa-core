<?php

declare(strict_types=1);

namespace Semitexa\Core\Locale;

use Semitexa\Core\Attribute\WorkerState;

/**
 * No-op locale context for use when semitexa-locale is not installed.
 *
 * Returns 'en' as the default locale. setLocale() is intentionally a no-op:
 * DefaultLocaleContext is a stateless fallback — it should never carry mutable
 * per-request state. When locale resolution is needed, install semitexa-locale
 * which provides a coroutine-safe LocaleManager backed by LocaleContextStore.
 */
final class DefaultLocaleContext implements LocaleContextInterface
{
    #[WorkerState('Stateless fallback singleton; always answers the default locale.')]
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
        return 'en';
    }

    /**
     * No-op — DefaultLocaleContext is a stateless fallback.
     * Install semitexa-locale for a request-scoped, coroutine-safe implementation.
     */
    public function setLocale(string $locale): void
    {
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
