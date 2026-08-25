<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Pipeline\ResponseRenderer;
use Semitexa\Locale\Context\LocaleContextStore;

/**
 * A redirect is the one internal link the application writes as a bare string,
 * so under URL-prefixed locales it is the one that loses the language.
 */
final class RedirectLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleContextStore::clearFallback();
        LocaleContextStore::setUrlPrefixEnabled(true);
        LocaleContextStore::setDefaultLocale('en');
        LocaleContextStore::setSupportedLocales(['uk', 'en', 'ka']);
    }

    protected function tearDown(): void
    {
        LocaleContextStore::clearFallback();
    }

    #[Test]
    public function a_root_relative_target_is_prefixed_for_a_non_default_locale(): void
    {
        LocaleContextStore::setLocale('uk');

        self::assertSame('/uk/app/properties', $this->rewrite('/app/properties'));
    }

    #[Test]
    public function the_query_string_survives(): void
    {
        LocaleContextStore::setLocale('ka');

        self::assertSame('/ka/app/finances?err=input', $this->rewrite('/app/finances?err=input'));
    }

    #[Test]
    public function the_default_locale_stays_on_the_bare_path(): void
    {
        LocaleContextStore::setLocale('en');

        self::assertSame('/app', $this->rewrite('/app'));
    }

    #[Test]
    public function an_already_prefixed_target_is_not_prefixed_twice(): void
    {
        LocaleContextStore::setLocale('uk');

        self::assertSame('/uk/app', $this->rewrite('/uk/app'));
    }

    #[Test]
    public function another_hosts_url_is_left_alone(): void
    {
        LocaleContextStore::setLocale('uk');

        self::assertSame('https://accounts.google.com/o/oauth2/auth', $this->rewrite('https://accounts.google.com/o/oauth2/auth'));
        self::assertSame('//account.example.com/app', $this->rewrite('//account.example.com/app'));
    }

    #[Test]
    public function nothing_happens_while_prefixing_is_off(): void
    {
        LocaleContextStore::setUrlPrefixEnabled(false);
        LocaleContextStore::setLocale('uk');

        self::assertSame('/app', $this->rewrite('/app'));
    }

    private function rewrite(string $url): string
    {
        $method = new \ReflectionMethod(ResponseRenderer::class, 'inCurrentLocale');

        return (string) $method->invoke(null, $url);
    }
}
