<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Locale;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Locale\LocaleSwitch;

/**
 * The pure parts of the language switch: the link that carries the choice and
 * what is taken back out of it. The phase's behaviour is in LocalePhaseTest.
 */
final class LocaleSwitchTest extends TestCase
{
    #[Test]
    public function the_link_carries_the_choice(): void
    {
        self::assertSame('/x?_locale=en', LocaleSwitch::mark('/x', 'en'));
        self::assertSame('/uk/x?_locale=uk', LocaleSwitch::mark('/uk/x', 'uk'));
        self::assertSame('/x?a=1&_locale=en', LocaleSwitch::mark('/x?a=1', 'en'));
        self::assertSame('/x?_locale=en#top', LocaleSwitch::mark('/x#top', 'en'));
        self::assertSame('/x?_locale=pt-BR', LocaleSwitch::mark('/x', 'pt-BR'));
    }

    #[Test]
    public function only_the_marker_leaves_the_query(): void
    {
        self::assertSame('', LocaleSwitch::withoutMarker('_locale=en'));
        self::assertSame('a=1&b=%20x', LocaleSwitch::withoutMarker('a=1&_locale=en&b=%20x'));
        self::assertSame('a=1', LocaleSwitch::withoutMarker('_locale=en&a=1&_locale=uk&%5Flocale=de&_locale[]=x&_locale'));
        self::assertSame('my_locale=en&_localex=1', LocaleSwitch::withoutMarker('my_locale=en&_localex=1'), 'a name that only contains it is another parameter');
        self::assertSame('', LocaleSwitch::withoutMarker(''));
    }

    /** PHP turns `.` and a space in a parameter name into `_` and drops leading spaces. */
    #[Test]
    public function a_name_php_reads_as_the_marker_leaves_too(): void
    {
        self::assertSame('a=1', LocaleSwitch::withoutMarker('.locale=en&a=1&%20_locale=de&+.locale=x'));
        self::assertSame(
            'locale=en&x.locale=1&+locale=uk&_locale%20=x&_locale.=y',
            LocaleSwitch::withoutMarker('locale=en&x.locale=1&+locale=uk&_locale%20=x&_locale.=y'),
            'names PHP reads as locale, x_locale and _locale_ are other parameters',
        );
    }

    #[Test]
    public function the_other_cookie_variant_for_a_host(): void
    {
        self::assertSame([null], LocaleSwitch::otherCookieDomains('app.example.com', '.example.com'), 'a domain is configured: the host-only one');
        self::assertSame(['app.example.com', 'example.com'], LocaleSwitch::otherCookieDomains('app.example.com', ''));
        self::assertSame(['example.com'], LocaleSwitch::otherCookieDomains('Example.com', ''));
        self::assertSame(['a.b.c.example.co.uk', 'b.c.example.co.uk', 'c.example.co.uk', 'example.co.uk'], LocaleSwitch::otherCookieDomains('a.b.c.example.co.uk', ''), 'bounded');
        self::assertSame([], LocaleSwitch::otherCookieDomains('localhost', ''));
        self::assertSame([], LocaleSwitch::otherCookieDomains('10.0.0.7', ''));
        self::assertSame([], LocaleSwitch::otherCookieDomains('', ''));
    }

    #[Test]
    public function a_marked_link_comes_back_as_the_page_it_marked(): void
    {
        $link = LocaleSwitch::mark('/uk/app/bookings', 'uk');
        [$path, $query] = explode('?', $link, 2);

        self::assertSame(
            '/uk/app/bookings',
            LocaleSwitch::target('/app/bookings', $query, 'GET', 'text/html', 'uk', 'en', ['en', 'uk']),
        );
        self::assertSame('/uk/app/bookings', $path);
    }

    #[Test]
    public function the_browsers_word_for_who_started_the_navigation(): void
    {
        self::assertFalse(LocaleSwitch::startedElsewhere(null), 'a browser that does not say is believed');
        self::assertFalse(LocaleSwitch::startedElsewhere('same-origin'));
        self::assertFalse(LocaleSwitch::startedElsewhere('none'));
        self::assertTrue(LocaleSwitch::startedElsewhere('cross-site'));
        self::assertTrue(LocaleSwitch::startedElsewhere('Same-Site'));
        self::assertTrue(LocaleSwitch::startedElsewhere('anything-new'), 'an unknown value is not our own page');
    }

    #[Test]
    public function a_fetch_ahead_of_a_click_is_recognised(): void
    {
        self::assertFalse(LocaleSwitch::isSpeculative(null, null));
        self::assertFalse(LocaleSwitch::isSpeculative(''));
        self::assertTrue(LocaleSwitch::isSpeculative(null, 'prefetch'));
        self::assertTrue(LocaleSwitch::isSpeculative('prefetch;prerender'));
        self::assertTrue(LocaleSwitch::isSpeculative(null, null, 'Preview'));
    }
}
