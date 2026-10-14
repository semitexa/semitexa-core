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
 * browser names a foreign caller in Sec-Fetch-Site; without that header, an
 * Origin that names another host (or is opaque, like `null`) gives it away.
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
        if (!is_array($parts) || !isset($parts['host'])) {
            return true; // `Origin: null` and the like: an opaque, foreign origin
        }
        $originHost = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $host = strtolower(trim((string) $request->getHeader('Host')));

        return $host === '' || ($originHost !== $host && strtolower($parts['host']) !== $host);
    }
}
