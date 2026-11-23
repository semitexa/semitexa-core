<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Request;

/**
 * Whether another site could read the response to this request.
 *
 * The app answers every route with `Access-Control-Allow-Origin: *`
 * (CORS_ALLOW_ORIGIN), so a developer surface (the Observatory, the UI
 * Workbench) that serves class names, file paths or trace ids must refuse a
 * page on another site that fetches it; measured 2026-10-02 against
 * /__observatory/graph. The same twenty lines lived in both gates.
 *
 * A top-level GET navigation is not a read: following a link from docs, chat
 * or another local app opens the page, and the other site cannot see it. A
 * browser names a foreign caller in Sec-Fetch-Site. Without that header, an
 * Origin that is not this request's own gives it away: another scheme, host
 * or port (a port left out is the scheme's default), or an opaque one like
 * `null`. `http://localhost:3000` is another origin than `Host: localhost`.
 */
final class CrossSiteRead
{
    public static function isCrossSiteRead(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        $mode = strtolower(trim((string) $request->getHeader('Sec-Fetch-Mode')));
        $dest = strtolower(trim((string) $request->getHeader('Sec-Fetch-Dest')));
        if ($mode === 'navigate' && $dest === 'document' && strtoupper($request->getMethod()) === 'GET') {
            return false;
        }

        $site = strtolower(trim((string) $request->getHeader('Sec-Fetch-Site')));
        if ($site === 'cross-site' || $site === 'same-site') {
            return true;
        }

        $origin = trim((string) $request->getHeader('Origin'));
        if ($origin === '') {
            return false;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return true; // `Origin: null` and the like: an opaque, foreign origin
        }
        $own = parse_url('http://' . strtolower(trim((string) $request->getHeader('Host'))));
        if (!is_array($own) || ($own['host'] ?? '') === '') {
            return true; // no host of its own to compare with
        }
        $originScheme = strtolower($parts['scheme']);
        $scheme = $request->getScheme();

        return $originScheme !== $scheme
            || strtolower($parts['host']) !== $own['host']
            || ($parts['port'] ?? self::defaultPort($originScheme)) !== ($own['port'] ?? self::defaultPort($scheme));
    }

    private static function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
