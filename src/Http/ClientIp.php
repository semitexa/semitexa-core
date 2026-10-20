<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Request;

/**
 * The address of the client behind a request, for decisions keyed on WHO is
 * asking (a rate limit), not for display.
 *
 * The peer itself unless it is a trusted proxy (TrustedProxies). Behind one,
 * X-Forwarded-For is read from the right, skipping hops that are trusted
 * proxies too: the first other hop is the one a trusted proxy saw connect.
 * Everything left of it was written by the client and proves nothing — a
 * client that sends `X-Forwarded-For: 1.2.3.4` only prepends to the list.
 */
final class ClientIp
{
    public static function of(Request $request): string
    {
        $peer = trim($request->getServer('remote_addr'));
        if (!TrustedProxies::trusts($peer)) {
            return $peer;
        }

        $hops = explode(',', (string) $request->getHeader('X-Forwarded-For'));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = trim($hops[$i]);
            if (@inet_pton($hop) === false) {
                // A hop that is no address is not something a proxy we trust
                // wrote; nothing to its left can be trusted either.
                return $peer;
            }
            if (!TrustedProxies::trusts($hop)) {
                return $hop;
            }
            $peer = $hop;
        }

        return $peer;
    }
}
