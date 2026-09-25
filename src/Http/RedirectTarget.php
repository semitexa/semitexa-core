<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * Decides whether a handler's redirect target may leave the response as-is,
 * or must fall back to `/` (VULN-006, open redirects).
 *
 * A target is kept when a BROWSER resolves it on this site: a same-origin
 * reference, this host or a sibling host of the same site, localhost, or one
 * of the OAuth providers a login flow hands off to. The browser is the judge,
 * not parse_url(): the two disagree on backslashes and ASCII controls, and
 * every disagreement is a way to another host.
 */
final class RedirectTarget
{
    /** Hosts a login flow legitimately hands off to. */
    private const ALLOWED_EXTERNAL_HOSTS = [
        'accounts.google.com',
        'login.microsoftonline.com',
        'github.com',
        'login.live.com',
        'appleid.apple.com',
    ];

    public static function sanitize(string $url, string $requestHost): string
    {
        // Before any parsing: a browser drops ASCII controls and reads `\`
        // as `/` (WHATWG), while parse_url() does neither. So
        // `https://evil.example\@site.test/` has host site.test for PHP and
        // evil.example for the browser, and a tab can join two slashes into
        // `//host`. A target carrying either is not trusted anywhere.
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1 || trim($url) !== $url) {
            return '/';
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            // Unparseable — reject.
            return '/';
        }

        if (!isset($parsed['host'])) {
            // Relative — allowed, but only when a browser reads it as one too.
            return self::isSameOriginReference($url, $parsed) ? $url : '/';
        }

        // Absolute: http(s) only, and a host of this site or an allowed one.
        if (isset($parsed['scheme']) && !in_array($parsed['scheme'], ['http', 'https'], true)) {
            return '/';
        }
        $redirectHost = strtolower($parsed['host']);
        $requestHost = strtolower($requestHost);
        if ($redirectHost === $requestHost
            || $redirectHost === 'localhost'
            || $redirectHost === '127.0.0.1'
            || self::isSiblingHost($redirectHost, $requestHost)
            || in_array($redirectHost, self::ALLOWED_EXTERNAL_HOSTS, true)) {
            return $url;
        }

        return '/';
    }

    /**
     * Does a browser resolve this host-less target on the current origin?
     *
     * parse_url() finding no host is not the browser finding none. Browsers
     * (WHATWG URL) read `\` as `/` in http(s) URLs and drop TAB/CR/LF anywhere,
     * so `/\evil.com`, `\\evil.com` and `/<TAB>/evil.com` all become
     * `//evil.com`; leading spaces are trimmed the same way. A scheme with no
     * authority is no safer: `https:/evil.com` from an http page skips the
     * missing slashes and lands on evil.com, and `javascript:` has no host at all.
     *
     * @param array<string, int|string> $parsed
     */
    private static function isSameOriginReference(string $url, array $parsed): bool
    {
        if (isset($parsed['scheme'])) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return false;
        }

        return !str_starts_with($url, ' ') && !str_starts_with($url, '//');
    }

    /**
     * Is this redirect target another host of the SAME site?
     *
     * An application can be split across hosts on purpose — a public site on
     * the apex and a cabinet on `account.`, say — and moving a visitor between
     * them is ordinary navigation, not an open redirect. The guard above had no
     * way to say that: its allow-list is a fixed set of OAuth providers, so an
     * application redirecting to its own sibling host had the target silently
     * replaced with '/', which looks like the redirect simply not working.
     *
     * The dot is the whole point. Matching on a bare suffix is the classic
     * version of this bug: `evil-example.com` ends with `example.com`, and an
     * attacker who can register that name gets exactly the open redirect this
     * check exists to prevent. Only a real label boundary counts, in either
     * direction — parent to child, child to parent.
     */
    private static function isSiblingHost(string $redirectHost, string $requestHost): bool
    {
        if ($redirectHost === '' || $requestHost === '') {
            return false;
        }

        // A single label ("localhost", "app") has no site to be a sibling of.
        if (!str_contains($redirectHost, '.') || !str_contains($requestHost, '.')) {
            return false;
        }

        return str_ends_with($requestHost, '.' . $redirectHost)
            || str_ends_with($redirectHost, '.' . $requestHost);
    }
}
