<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Log\StaticLoggerBridge;
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
    /** Said once per worker: the condition is the deployment's, not a request's. */
    private static bool $warnedUntrustedProxy = false;

    public static function of(Request $request): string
    {
        $peer = trim($request->getServer('remote_addr'));
        if (!TrustedProxies::trusts($peer)) {
            if (!self::$warnedUntrustedProxy && $request->getHeader('x-forwarded-for') !== null && self::isPrivate($peer)) {
                self::warnUntrustedProxy($peer);
            }

            return $peer;
        }

        $hops = explode(',', (string) $request->getHeader('X-Forwarded-For'));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = self::address(trim($hops[$i]));
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

    /**
     * A proxy on a private address that is not trusted: every client then
     * counts as that address, so one visitor's failed sign-ins lock out
     * every visitor. doctor cannot see it (SESSION_COOKIE_SECURE=always
     * passes its check without TRUSTED_PROXIES), so it is said here.
     */
    private static function warnUntrustedProxy(string $peer): void
    {
        self::$warnedUntrustedProxy = true;
        StaticLoggerBridge::warning('http', 'X-Forwarded-For arrived from a peer TRUSTED_PROXIES does not list; every client behind it counts as that one address (rate limits, sign-in limits)', [
            'peer' => $peer,
            'fix' => 'set TRUSTED_PROXIES to the proxy address or CIDR',
        ]);
    }

    private static function isPrivate(string $address): bool
    {
        // An IPv4 client on a dual-stack listener (SWOOLE_HOST=::) is
        // ::ffff:a.b.c.d, which PHP counts as reserved whatever a.b.c.d is:
        // any outside client sending the header was reported as a proxy.
        $address = strtolower($address);
        if (str_starts_with($address, '::ffff:') && filter_var(substr($address, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $address = substr($address, 7);
        }

        return @inet_pton($address) !== false
            && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** `1.2.3.4:5678`, `[2001:db8::1]` and `[2001:db8::1]:443` are written by real proxies. */
    private static function address(string $hop): string
    {
        if (str_starts_with($hop, '[')) {
            $end = strpos($hop, ']');

            return $end === false ? $hop : substr($hop, 1, $end - 1);
        }

        return substr_count($hop, ':') === 1 ? explode(':', $hop, 2)[0] : $hop;
    }
}
