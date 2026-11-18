<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use Semitexa\Core\Tests\Support\StaticState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Lifecycle\LocalePhase;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Request;
use Semitexa\Locale\Application\Service\LocaleBootstrapper;
use Semitexa\Locale\Configuration\LocaleConfig;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Locale\Context\LocaleManager;

/**
 * `/en/...` under URL prefixes is answered with a 301 to the bare path. The
 * bare path is whatever followed the prefix, so a second slash or a backslash
 * right after it turned the Location into a protocol-relative URL on another
 * host.
 *
 * And the language switcher: with a `locale` cookie naming another language,
 * the default language's bare path was redirected straight back, so the
 * framework's own switcher could not reach it. Its link now carries
 * `?_locale=<code>`, which the phase reads as the choice.
 */
final class LocalePhaseTest extends TestCase
{
    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $localeState;

    private string|false $cookieDomainBefore = false;

    private string|false $cookieSecureBefore = false;

    protected function setUp(): void
    {
        // The switch writes its cookie on the session's domain: pinned to
        // "none configured" so a developer's .env cannot change the answer.
        $this->cookieDomainBefore = getenv('SESSION_COOKIE_DOMAIN');
        putenv('SESSION_COOKIE_DOMAIN=');
        // And to "never Secure": the exact Set-Cookie lines below hold under
        // any deployment's SESSION_COOKIE_SECURE (review, core#250).
        $this->cookieSecureBefore = getenv('SESSION_COOKIE_SECURE');
        putenv('SESSION_COOKIE_SECURE=never');

        // Snapshot BEFORE resetting: whatever an earlier test left in the
        // store is put back in tearDown(), so this class leaves no trace.
        $this->localeState = StaticState::snapshot(LocaleContextStore::class);
        LocaleContextStore::clearFallback();
    }

    protected function tearDown(): void
    {
        $this->cookieDomainBefore === false
            ? putenv('SESSION_COOKIE_DOMAIN')
            : putenv('SESSION_COOKIE_DOMAIN=' . $this->cookieDomainBefore);
        $this->cookieSecureBefore === false
            ? putenv('SESSION_COOKIE_SECURE')
            : putenv('SESSION_COOKIE_SECURE=' . $this->cookieSecureBefore);
        StaticState::restore($this->localeState);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function defaultLocaleRedirects(): iterable
    {
        yield 'ordinary path' => ['/en/app/bookings', '', '/app/bookings'];
        yield 'prefix alone' => ['/en', '', '/'];
        yield 'query string kept' => ['/en/app', 'tab=2', '/app?tab=2'];
        yield 'double slash after prefix' => ['/en//evil.example/x', '', '/evil.example/x'];
        yield 'backslash after prefix' => ['/en/\\evil.example', '', '/evil.example'];
        yield 'mixed slashes after prefix' => ['/en/\\/\\evil.example', 'a=1', '/evil.example?a=1'];
        // Browsers drop tab/newline/CR from a Location: `/\t/evil.example`
        // became `//evil.example`, another host.
        yield 'tab between slashes after prefix' => ["/en/\t/evil.example", '', '/'];
        yield 'newline between slashes after prefix' => ["/en/\n/evil.example", '', '/'];
        yield 'backslash after a later slash' => ['/en/a\\b', '', '/a/b'];
    }

    #[Test]
    #[DataProvider('defaultLocaleRedirects')]
    public function the_default_locale_prefix_redirect_stays_on_this_host(string $path, string $query, string $expected): void
    {
        $uri = $query === '' ? $path : $path . '?' . $query;
        $request = new Request(
            method: 'GET',
            uri: $uri,
            headers: ['Host' => 'site.test'],
            query: [],
            post: [],
            server: ['request_uri' => $uri, 'query_string' => $query],
            cookies: [],
        );

        $bootstrapper = new LocaleBootstrapper(new LocaleManager(), new LocaleConfig(
            supportedLocales: ['en', 'uk'],
            resolverPriority: ['path'],
            urlPrefixEnabled: true,
        ));
        $phase = new LocalePhase(new RequestScopedContainer(new EmptyContainer()), $bootstrapper);
        $context = new RequestLifecycleContext($request);

        $phase->execute($context);

        self::assertTrue($context->hasEarlyResponse());
        $response = $context->getEarlyResponse();
        self::assertSame(301, $response->getStatusCode());
        self::assertSame($expected, $response->getHeaders()['Location'] ?? null);
    }

    private const HTML = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    /**
     * The reported trap, end to end: the visitor's cookie says Ukrainian, they
     * click the default language, and they must arrive on the default page
     * with the cookie saying so, where they then stay.
     */
    #[Test]
    public function the_default_language_is_reachable_from_behind_the_cookie(): void
    {
        [$response, $setCookies] = $this->visit('/app/bookings?_locale=en', ['locale' => 'uk']);

        self::assertNotNull($response, 'the switch link was served as a page');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app/bookings', $response->getHeaders()['Location'] ?? null);
        self::assertSame('no-store', $response->getHeaders()['Cache-Control'] ?? null);
        self::assertSame(
            ['locale=en', 'Max-Age=31536000', 'Path=/', 'SameSite=lax'],
            explode('; ', $setCookies[0] ?? ''),
            'readable by scripts (no HttpOnly): an application that switches client-side must be able to replace it',
        );

        // The browser follows the redirect with the cookie it was just given.
        [$landed] = $this->visit('/app/bookings', ['locale' => 'en']);
        self::assertNull($landed, 'the default page was redirected away again');
    }

    /**
     * Why the redirect exists (2026-08-26, "the cabinet came back in
     * English"): a bookmark or an email link carries no prefix and no marker,
     * and the person who chose Ukrainian still gets Ukrainian.
     */
    #[Test]
    public function a_bare_link_still_takes_a_stored_preference_to_its_own_url(): void
    {
        [$response, $setCookies] = $this->visit('/app/bookings?tab=2', ['locale' => 'uk']);

        self::assertNotNull($response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/uk/app/bookings?tab=2', $response->getHeaders()['Location'] ?? null);
        self::assertSame('Cookie, Accept', $response->getHeaders()['Vary'] ?? null);
        self::assertSame([], $setCookies, 'following a link is not a choice');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function switches(): iterable
    {
        yield 'to another prefixed language' => ['/de/app?_locale=de', '/de/app', 'de'];
        yield 'the rest of the query is kept' => ['/de/app?tab=2&_locale=de&q=a%20b', '/de/app?tab=2&q=a%20b', 'de'];
        yield 'the marker decides, not the prefix it sits behind' => ['/uk/app?_locale=de', '/de/app', 'de'];
        yield 'from a bare path to a prefixed language' => ['/app?_locale=de', '/de/app', 'de'];
        yield 'from a prefixed page to the default' => ['/uk/app?_locale=en', '/app', 'en'];
        yield 'the default prefix with the marker is one hop, not two' => ['/en/app?_locale=en', '/app', 'en'];
        yield 'the home page' => ['/?_locale=de', '/de/', 'de'];
    }

    #[Test]
    #[DataProvider('switches')]
    public function a_switch_lands_on_the_chosen_language_and_stores_it(string $uri, string $location, string $stored): void
    {
        [$response, $setCookies] = $this->visit($uri, ['locale' => 'uk']);

        self::assertNotNull($response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($location, $response->getHeaders()['Location'] ?? null);
        self::assertSame(['locale=' . $stored . '; Max-Age=31536000; Path=/; SameSite=lax'], $setCookies);
    }

    /** @return iterable<string, array{string}> */
    public static function markersThatAreNotAChoice(): iterable
    {
        yield 'a language the site does not have' => ['pl'];
        yield 'another host' => ['//evil.example'];
        yield 'a full url' => ['https://evil.example/'];
        yield 'a path' => ['../uk'];
        yield 'a supported code in another case' => ['EN'];
        yield 'a header injection' => ["en\r\nSet-Cookie: a=b"];
        yield 'empty' => [''];
    }

    /**
     * A marker that names no supported language is an ordinary unknown
     * parameter: the request is handled as it always was, here by the
     * preference redirect, and nothing of the value reaches a header.
     */
    #[Test]
    #[DataProvider('markersThatAreNotAChoice')]
    public function a_marker_that_names_no_supported_language_changes_nothing(string $marker): void
    {
        $query = '_locale=' . rawurlencode($marker);
        [$response, $setCookies] = $this->visit('/app?' . $query, ['locale' => 'uk']);

        self::assertNotNull($response);
        self::assertSame('/uk/app?' . $query, $response->getHeaders()['Location'] ?? null);
        self::assertSame([], $setCookies);
    }

    #[Test]
    public function an_array_marker_is_not_a_choice_either(): void
    {
        [$response, $setCookies] = $this->visit('/app?_locale[]=en', ['locale' => 'uk']);

        self::assertNotNull($response);
        self::assertSame('/uk/app?_locale[]=en', $response->getHeaders()['Location'] ?? null);
        self::assertSame([], $setCookies);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function hostilePaths(): iterable
    {
        yield 'double slash after the prefix' => ['/uk//evil.example/x', 'en', '/evil.example/x'];
        yield 'backslash after the prefix' => ['/uk/\\evil.example', 'en', '/evil.example'];
        yield 'tab between slashes' => ["/uk/\t/evil.example", 'en', '/'];
        yield 'double slash, prefixed target' => ['/uk//evil.example', 'de', '/de/evil.example'];
        yield 'backslash on a bare path' => ['/\\evil.example', 'en', '/evil.example'];
    }

    /** The redirect is built from the request's own path: it must stay a path on this host. */
    #[Test]
    #[DataProvider('hostilePaths')]
    public function a_switch_never_redirects_to_another_host(string $path, string $marker, string $expected): void
    {
        [$response] = $this->visit($path . '?_locale=' . $marker, ['locale' => 'uk']);

        self::assertNotNull($response);
        self::assertSame($expected, $response->getHeaders()['Location'] ?? null);
    }

    /** @return iterable<string, array{string, array<string, string>, array<string, string>}> */
    public static function switchesThatStoreNothing(): iterable
    {
        yield 'no stored preference to bring in line' => ['GET', [], []];
        yield 'the preference already says so' => ['GET', ['locale' => 'en'], []];
        yield 'a link on another site' => ['GET', ['locale' => 'uk'], ['Sec-Fetch-Site' => 'cross-site']];
        yield 'a link on a sibling host' => ['GET', ['locale' => 'uk'], ['Sec-Fetch-Site' => 'same-site']];
        yield 'a HEAD changes nothing' => ['HEAD', ['locale' => 'uk'], []];
    }

    /**
     * A page request with a valid marker is still redirected; what
     * is withheld is the write. The visitor a hostile link sent here keeps
     * their own language: the next hop is the preference redirect.
     *
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('switchesThatStoreNothing')]
    public function the_cookie_is_only_updated_by_the_visitors_own_click(string $method, array $cookies, array $headers): void
    {
        [$response, $setCookies] = $this->visit('/app?_locale=en', $cookies, $headers, $method);

        self::assertNotNull($response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app', $response->getHeaders()['Location'] ?? null);
        self::assertSame([], $setCookies);
    }

    #[Test]
    public function the_visitors_own_click_is_what_the_browser_calls_same_origin(): void
    {
        [, $sameOrigin] = $this->visit('/app?_locale=en', ['locale' => 'uk'], ['Sec-Fetch-Site' => 'same-origin']);
        [, $typed] = $this->visit('/app?_locale=en', ['locale' => 'uk'], ['Sec-Fetch-Site' => 'none']);

        self::assertSame(['locale=en; Max-Age=31536000; Path=/; SameSite=lax'], $sameOrigin);
        self::assertSame(['locale=en; Max-Age=31536000; Path=/; SameSite=lax'], $typed);
    }

    /**
     * A browser that fetches the link before any click must not change the
     * language, and must not keep a redirect it could replay on the real
     * click without the cookie change: the fetch is refused.
     */
    #[Test]
    public function a_prefetch_of_the_switch_link_is_refused(): void
    {
        foreach ([['Sec-Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender'], ['Purpose' => 'prefetch'], ['X-Moz' => 'prefetch']] as $headers) {
            [$response, $setCookies] = $this->visit('/app?_locale=en', ['locale' => 'uk'], $headers);

            self::assertNotNull($response);
            self::assertSame(503, $response->getStatusCode(), (string) json_encode($headers));
            self::assertArrayNotHasKey('Location', $response->getHeaders());
            self::assertSame('no-store', $response->getHeaders()['Cache-Control'] ?? null);
            self::assertSame([], $setCookies);
        }

        // With nothing to change, the redirect is the same for everyone and safe to prefetch.
        [$response] = $this->visit('/app?_locale=en', [], ['Sec-Purpose' => 'prefetch']);
        self::assertSame(302, $response?->getStatusCode());
    }

    /**
     * A redirect drops a body, and only a page has a translation: the same
     * two guards as the preference redirect.
     */
    #[Test]
    public function a_post_and_a_json_request_are_left_alone(): void
    {
        [$post, $postCookies] = $this->visit('/app?_locale=en', ['locale' => 'uk'], [], 'POST');
        [$json, $jsonCookies] = $this->visit('/app?_locale=en', ['locale' => 'uk'], ['Accept' => 'application/json']);
        [$prefixedJson] = $this->visit('/de/app?_locale=en', ['locale' => 'uk'], ['Accept' => 'application/json']);

        self::assertNull($post);
        self::assertNull($json);
        self::assertNull($prefixedJson);
        self::assertSame([], $postCookies);
        self::assertSame([], $jsonCookies);
    }

    #[Test]
    public function without_url_prefixes_the_marker_means_nothing(): void
    {
        [$response, $setCookies] = $this->visit('/app?_locale=en', ['locale' => 'uk'], [], 'GET', false);

        self::assertNull($response);
        self::assertSame([], $setCookies);
    }

    /**
     * A browser keeps a host-only `locale` and a Domain one side by side and
     * sends both, the older first. Updating one variant left the other, still
     * saying `uk`, in front: the default language stayed unreachable. The
     * other variant is expired in the same answer, before the new value.
     */
    #[Test]
    public function with_a_session_domain_the_host_only_cookie_is_expired(): void
    {
        putenv('SESSION_COOKIE_DOMAIN=.site.test');

        [$response, $stored, $expired] = $this->visit('/app?_locale=en', ['locale' => 'uk'], ['Host' => 'app.site.test']);

        self::assertSame('/app', $response?->getHeaders()['Location'] ?? null);
        self::assertSame(['locale=en; Max-Age=31536000; Path=/; Domain=.site.test; SameSite=lax'], $stored);
        self::assertCount(1, $expired);
        self::assertMatchesRegularExpression('/^locale=; Expires=[^;]+; Max-Age=0; Path=\/$/', $expired[0], 'host-only: no Domain attribute');
    }

    #[Test]
    public function without_a_session_domain_the_domain_cookies_are_expired(): void
    {
        [, $stored, $expired, $order] = $this->visit('/app?_locale=en', ['locale' => 'uk'], ['Host' => 'App.Site.Test:8443']);

        self::assertSame(['locale=en; Max-Age=31536000; Path=/; SameSite=lax'], $stored, 'the new cookie stays host-only');
        self::assertCount(2, $expired);
        self::assertMatchesRegularExpression('/^locale=; Expires=[^;]+; Max-Age=0; Path=\/; Domain=app\.site\.test$/', $expired[0]);
        self::assertMatchesRegularExpression('/^locale=; Expires=[^;]+; Max-Age=0; Path=\/; Domain=site\.test$/', $expired[1], 'the parent an application may have written it for');
        self::assertSame(
            ['expire', 'expire', 'store'],
            $order,
            'expiries first: a cookie store that counts host-only and Domain=<host> as one must end with the new value',
        );
    }

    #[Test]
    public function an_address_or_a_bare_name_has_no_domain_cookies_to_expire(): void
    {
        foreach (['127.0.0.1:9502', 'localhost', '[::1]:9502'] as $host) {
            [, $stored, $expired] = $this->visit('/app?_locale=en', ['locale' => 'uk'], ['Host' => $host]);

            self::assertSame(['locale=en; Max-Age=31536000; Path=/; SameSite=lax'], $stored, $host);
            self::assertSame([], $expired, $host);
        }
    }

    #[Test]
    public function a_switch_that_stores_nothing_expires_nothing(): void
    {
        [$response, $stored, $expired] = $this->visit('/app?_locale=en', ['locale' => 'uk'], ['Host' => 'app.site.test', 'Sec-Fetch-Site' => 'cross-site']);

        self::assertSame('/app', $response?->getHeaders()['Location'] ?? null);
        self::assertSame([], $stored);
        self::assertSame([], $expired);
    }

    /** @return iterable<string, array{array<string, array<mixed>|string>, string, list<string>}> */
    public static function swooleQueries(): iterable
    {
        $stored = ['locale=en; Max-Age=31536000; Path=/; SameSite=lax'];

        yield 'the marker alone' => [['_locale' => 'en'], '/app', $stored];
        // ?_locale=uk&_locale=en&q=1: the parser keeps the last value.
        yield 'a repeated marker' => [['_locale' => 'en', 'q' => '1'], '/app?q=1', $stored];
        yield 'the rest is rebuilt from the parsed parameters' => [
            ['tab' => '2', '_locale' => 'en', 'q' => "a b\r", 'f' => ['x' => '1']],
            '/app?tab=2&q=a+b%0D&f%5Bx%5D=1',
            $stored,
        ];
        // ?_locale[]=en is no choice: the stored preference redirects as ever.
        yield 'an array marker' => [['_locale' => ['en']], '/uk/app?_locale%5B0%5D=en', []];
        // ?.locale=en reaches the request as _locale, and is the marker.
        yield 'a name the parser turned into the marker' => [['_locale' => 'en', 'a' => '1'], '/app?a=1', $stored];
    }

    /**
     * Under Swoole the uri is the path alone and the query arrives parsed, so
     * the Location is built from the parameters, not from the text the
     * browser sent. The other tests here put the query in the uri.
     *
     * @param array<string, array<mixed>|string> $query
     * @param list<string>                       $expectedStored
     */
    #[Test]
    #[DataProvider('swooleQueries')]
    public function a_switch_under_swoole_is_read_from_the_parsed_query(array $query, string $location, array $expectedStored): void
    {
        [$response, $stored] = $this->visit('/app', ['locale' => 'uk'], [], 'GET', true, $query);

        self::assertNotNull($response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($location, $response->getHeaders()['Location'] ?? null);
        self::assertSame($expectedStored, $stored);
    }

    /**
     * PHP reads `.locale`, `+.locale` and `%20_locale` as `_locale`, so each is
     * the marker to the request. Left in the target, the target was the
     * marker URL again: a redirect to itself.
     */
    #[Test]
    public function a_name_php_reads_as_the_marker_does_not_survive_into_the_target(): void
    {
        foreach (['.locale=en', '+.locale=en', '%20_locale=en', '%5Flocale=en', '_locale=uk&.locale=en'] as $queryString) {
            [$response] = $this->visit('/app?a=1&' . $queryString, ['locale' => 'uk']);

            self::assertSame('/app?a=1', $response?->getHeaders()['Location'] ?? null, $queryString);
        }
    }

    /**
     * One request through the phase, as a browser would send it.
     *
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     *
     * @param array<string, array<mixed>|string>|null $swooleQuery the parsed query of a request whose uri is the path alone, as Swoole delivers it
     *
     * @return array{0: ?\Semitexa\Core\HttpResponse, 1: list<string>, 2: list<string>, 3: list<string>} the early response, if any; the Set-Cookie lines that store a value; the ones that expire one; and the order the two kinds came in
     */
    private function visit(string $uri, array $cookies, array $headers = [], string $method = 'GET', bool $urlPrefix = true, ?array $swooleQuery = null): array
    {
        $queryString = (string) parse_url('http://site.test' . $uri, PHP_URL_QUERY);
        $query = $swooleQuery;
        if ($query === null) {
            $query = [];
            parse_str($queryString, $parsed);
            foreach ($parsed as $name => $value) {
                $query[(string) $name] = $value;
            }
        } else {
            $queryString = http_build_query($query);
        }

        $request = new Request(
            method: $method,
            uri: $uri,
            headers: $headers + ['Host' => 'site.test', 'Accept' => self::HTML],
            query: $query,
            post: [],
            server: ['request_uri' => $uri, 'query_string' => $queryString],
            cookies: $cookies,
        );

        $container = new RequestScopedContainer(new EmptyContainer());
        $jar = new CookieJar($request);
        $container->set(CookieJarInterface::class, $jar);

        $bootstrapper = new LocaleBootstrapper(new LocaleManager(), new LocaleConfig(
            supportedLocales: ['en', 'uk', 'de'],
            resolverPriority: ['path'],
            urlPrefixEnabled: $urlPrefix,
        ));
        $context = new RequestLifecycleContext($request);

        (new LocalePhase($container, $bootstrapper))->execute($context);

        $stored = [];
        $expired = [];
        $order = [];
        foreach ($jar->getSetCookieLines() as $line) {
            self::assertIsString($line);
            if (str_contains($line, 'Max-Age=0')) {
                $expired[] = $line;
                $order[] = 'expire';
            } else {
                $stored[] = $line;
                $order[] = 'store';
            }
        }

        return [$context->hasEarlyResponse() ? $context->getEarlyResponse() : null, $stored, $expired, $order];
    }
}

final class EmptyContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new \RuntimeException("no entry {$id}");
    }

    public function has(string $id): bool
    {
        return false;
    }
}
