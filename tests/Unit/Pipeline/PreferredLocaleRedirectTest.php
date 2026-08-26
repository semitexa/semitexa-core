<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\LocalePhase;

/**
 * With URL prefixes on, a path without a prefix means the default language.
 * That is what stops one address from serving three pages — and it is also what
 * silently disables a stored language preference, because nothing about the URL
 * says who is reading it.
 *
 * A person who chose a language is sent to that language's URL. Everything else
 * — crawlers, images, form posts, feeds — must be left exactly as it was.
 */
final class PreferredLocaleRedirectTest extends TestCase
{
    private const SUPPORTED = ['uk', 'en', 'ka'];
    private const HTML = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    #[Test]
    public function a_chosen_language_sends_the_reader_to_its_own_url(): void
    {
        self::assertSame('/uk/app/bookings', $this->target('/app/bookings', cookie: 'uk'));
    }

    #[Test]
    public function the_query_string_survives_the_move(): void
    {
        self::assertSame(
            '/uk/app/finances?month=2026-08',
            $this->target('/app/finances', query: 'month=2026-08', cookie: 'uk'),
        );
    }

    /**
     * The crawler's case, and the one that must never change: no cookie, so the
     * bare path keeps meaning the default language and the indexed page stays
     * the page that was indexed.
     */
    #[Test]
    public function a_request_with_no_preference_is_left_alone(): void
    {
        self::assertNull($this->target('/gallery', cookie: ''));
    }

    #[Test]
    public function a_preference_for_the_default_language_moves_nobody(): void
    {
        self::assertNull($this->target('/gallery', cookie: 'en'));
    }

    #[Test]
    public function an_unsupported_preference_is_ignored_rather_than_obeyed(): void
    {
        self::assertNull($this->target('/gallery', cookie: 'pl'));
        self::assertNull($this->target('/gallery', cookie: '../etc'));
    }

    /**
     * A post carries a body. Redirecting it would drop the body, and the write
     * the person just asked for would quietly not happen.
     */
    #[Test]
    public function a_form_post_is_never_redirected(): void
    {
        self::assertNull($this->target('/app/properties', method: 'POST', cookie: 'uk'));
    }

    /**
     * This decision is made before routing, so it cannot know what a URL serves.
     * A prefix in front of an image or a feed is a 404, not a translation — and
     * those are most of the GETs a browser makes on a page.
     */
    #[Test]
    public function only_a_request_for_a_page_is_moved(): void
    {
        self::assertNull($this->target('/media/abc/hero', accept: 'image/avif,image/webp,*/*', cookie: 'uk'));
        self::assertNull($this->target('/__semitexa_hug', accept: 'application/json', cookie: 'uk'));
        self::assertNull($this->target('/ical/abc/token.ics', accept: null, cookie: 'uk'));
    }

    #[Test]
    public function a_head_request_is_treated_as_the_get_it_previews(): void
    {
        self::assertSame('/ka/gallery', $this->target('/gallery', method: 'HEAD', cookie: 'ka'));
    }

    /**
     * The redirect only ever fires on a path that carries no prefix — the caller
     * guarantees that — so the target always does. Asserted here because a
     * target that could itself be redirected is an infinite loop in a browser.
     */
    #[Test]
    public function the_target_carries_a_prefix_so_it_cannot_bounce_again(): void
    {
        $target = (string) $this->target('/app', cookie: 'uk');

        self::assertNull($this->target($target, cookie: 'uk'), 'the target redirected again');
    }

    private function target(
        string $path,
        string $query = '',
        string $method = 'GET',
        ?string $accept = self::HTML,
        string $cookie = '',
    ): ?string {
        return LocalePhase::preferredLocaleTarget(
            $path,
            $query,
            $method,
            $accept,
            $cookie,
            'en',
            self::SUPPORTED,
        );
    }
}
