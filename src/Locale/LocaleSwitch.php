<?php

declare(strict_types=1);

namespace Semitexa\Core\Locale;

/**
 * The language switcher's link says which language was chosen.
 *
 * With URL prefixes on, the default language lives on the bare path, and a
 * bare path with a `locale` cookie naming another language is sent to that
 * language's URL (see LocalePhase). Both are right on their own, and together
 * they made the default language unreachable: the switcher linked to `/x`, the
 * cookie said `uk`, and the visitor was sent back to `/uk/x`. Nothing in `/x`
 * told a click on "English" from a bookmark.
 *
 * So `locale_switch_url()` adds `?_locale=<code>`, and the locale phase reads
 * it as the one thing a stored preference cannot outrank: it brings the cookie
 * in line and redirects to the same page without the marker. A page request
 * (GET or HEAD asking for text/html) with a valid marker is always answered
 * with that redirect, so no page is indexed or cached under a marker URL by
 * a browser or a crawler; any other request is served as if the parameter
 * were not there.
 *
 * Everything here is a pure decision, so it can be read and tested without a
 * request, a container and a locale pack.
 */
final class LocaleSwitch
{
    /** The query parameter `locale_switch_url()` adds and the locale phase consumes. */
    public const QUERY_PARAMETER = '_locale';

    /**
     * A switcher link: `$url` with the marker for `$locale` added.
     */
    public static function mark(string $url, string $locale): string
    {
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        return $url
            . (str_contains($url, '?') ? '&' : '?')
            . self::QUERY_PARAMETER . '=' . rawurlencode($locale)
            . $fragment;
    }

    /**
     * Where a request carrying the marker is sent: the same page in the chosen
     * language, without the marker. Null when the request is not a switch, and
     * is then handled as if the parameter were not there.
     *
     * The target is built from this request's own path and the locale list,
     * never from the marker's text: a value that is not a supported locale,
     * spelled exactly, is not a choice.
     *
     * @param string        $path             the request path with no locale prefix
     * @param array<string> $supportedLocales
     */
    public static function target(
        string $path,
        string $queryString,
        string $method,
        ?string $accept,
        string $chosen,
        string $defaultLocale,
        array $supportedLocales,
    ): ?string {
        if ($chosen === '' || !in_array($chosen, $supportedLocales, true)) {
            return null;
        }

        // The same two guards as the preference redirect, for the same
        // reasons: a redirect drops a body, and only a page has a translation.
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }
        if (!str_contains(strtolower($accept ?? ''), 'text/html')) {
            return null;
        }

        $path = self::onThisSite($path);
        $target = $chosen === $defaultLocale ? $path : '/' . $chosen . $path;
        $query = self::withoutMarker($queryString);

        return $query === '' ? $target : $target . '?' . $query;
    }

    /**
     * A query string with every form of the marker removed, the other pairs
     * passed on as they were given. (Given by Request::getQueryString(): under
     * Swoole that is the parsed parameters built back into a string, so `%0d`
     * arrives here as `%0D` already.)
     *
     * Every form means every name PHP reads as `_locale`: it turns `.` and a
     * space in a name into `_` and drops leading spaces, so `?.locale=en` IS
     * the marker to the request, and left in the target it was a redirect to
     * itself. Each pair is therefore asked the way the request was parsed.
     */
    public static function withoutMarker(string $queryString): string
    {
        $kept = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }
            parse_str($pair, $parsed);
            if (array_key_exists(self::QUERY_PARAMETER, $parsed)) {
                continue;
            }
            $kept[] = $pair;
        }

        return implode('&', $kept);
    }

    /**
     * A path with exactly one leading slash and nothing a browser would read
     * as another host. `//evil.example` and `/\evil.example` are
     * protocol-relative; browsers drop tab, newline and CR, so
     * `/<tab>/evil.example` is too: a path with ASCII controls becomes `/`.
     */
    public static function onThisSite(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return preg_match('/[\x00-\x1F\x7F]/', $path) === 1 ? '/' : '/' . ltrim($path, '/');
    }

    /**
     * Whether the browser says this navigation was started by another site.
     *
     * A switch changes a stored preference with a GET, so a link on another
     * site could change it for the visitor. Browsers name the initiator in
     * Sec-Fetch-Site: `same-origin` is our own switcher, `none` is the address
     * bar or a bookmark. Anything else is not the visitor's choice made here.
     * A client that sends no such header (an old browser) is believed.
     */
    public static function startedElsewhere(?string $secFetchSite): bool
    {
        $site = strtolower(trim($secFetchSite ?? ''));

        return $site !== '' && $site !== 'same-origin' && $site !== 'none';
    }

    /**
     * The other `locale` cookies a browser may hold for this host besides the
     * one about to be written, as Domain attributes; null is the host-only one.
     *
     * A browser keeps a host-only cookie and a Domain cookie of the same name
     * side by side and sends both, the older first. So an application cookie
     * written host-only on a site whose session domain is `.example.com` (or
     * the reverse) survived the update and went on winning: the default
     * language stayed unreachable. They are expired in the same answer.
     *
     * With a configured domain the other one is the host-only cookie. With
     * none, it is a Domain cookie for this host or a parent of it; a
     * single-label parent (`com`) is never a cookie domain, and a public
     * suffix among the rest (`co.uk`) is refused by the browser, which costs
     * a header line and nothing else.
     *
     * @return list<?string>
     */
    public static function otherCookieDomains(string $host, string $configuredDomain): array
    {
        if (ltrim($configuredDomain, '.') !== '') {
            return [null];
        }

        $host = strtolower(trim($host, '.'));
        if ($host === '' || !str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $domains = [];
        $labels = explode('.', $host);
        while (count($labels) >= 2 && count($domains) < 4) {
            $domains[] = implode('.', $labels);
            array_shift($labels);
        }

        return $domains;
    }

    /**
     * Whether the browser is fetching the link ahead of a click that may never
     * come (`Sec-Purpose: prefetch`, `prefetch;prerender`; `Purpose`,
     * `X-Purpose: preview` and `X-Moz` are the older spellings).
     */
    public static function isSpeculative(?string ...$purposeHeaders): bool
    {
        foreach ($purposeHeaders as $value) {
            $value = strtolower($value ?? '');
            if (str_contains($value, 'prefetch') || str_contains($value, 'prerender') || str_contains($value, 'preview')) {
                return true;
            }
        }

        return false;
    }
}
