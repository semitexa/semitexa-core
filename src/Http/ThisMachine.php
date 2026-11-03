<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Environment;
use Semitexa\Core\Request;

/**
 * Whether a request was sent from the machine the app runs on, under a name
 * only that machine resolves: the bar for a developer surface that answers
 * without a sign-in (the Observatory, the Explorer sandbox, the UI Workbench).
 *
 * APP_ENV=dev used to be enough, and the dev stack publishes the app port on
 * every interface: measured 2026-11-03, anyone on the same Wi-Fi read the
 * Observatory graph (300 KB of routes, handlers and files) and ran requests
 * through the Explorer sandbox at http://<laptop-ip>:<port>/. A page on any
 * site could do the same through DNS rebinding — its own name pointed at
 * 127.0.0.1 — since CrossSiteRead sees a same-origin request then.
 *
 * Two conditions, both required:
 *
 * - **The peer is this machine.** Loopback, or the container's default
 *   gateway: inside the image a request from the host (a browser on
 *   localhost:<port>, the shared local router) arrives from the bridge
 *   gateway, while one from another machine keeps its own address. Forwarded
 *   headers, when present, must name loopback or a Docker bridge
 *   gateway: the local router forwards the address it saw, and a browser on
 *   the host reaches it through its network's gateway (measured: 172.23.0.1).
 *   Only a gateway, x.y.0.1 in Docker's 172.16.0.0/12 pool: any other address
 *   there could be a machine on a LAN numbered from the same range. A network
 *   with its own subnet or gateway (docker network create --gateway) names
 *   its gateway exactly in DEV_LOCAL_GATEWAYS, comma-separated; its other
 *   addresses stay refused.
 * - **The Host is a local name**: localhost, *.localhost, a loopback literal or
 *   a *.test name (the local router's zone, resolved only here). A rebinding
 *   attacker controls only names that resolve publicly. No Host at all is
 *   left to the peer: rebinding needs a browser, which always sends one.
 *
 * A request built in-process (a test, a CLI render) has no peer and counts as
 * local: Swoole sets remote_addr on every connection it accepts.
 */
final class ThisMachine
{
    private const FORWARDING_HEADERS = ['X-Forwarded-For', 'X-Real-Ip', 'Forwarded', 'True-Client-Ip'];

    /** Read once per worker: a running container keeps its network. */
    private static ?string $gateway = null;

    private static string $routeFile = '/proc/net/route';

    public static function sent(?Request $request): bool
    {
        if ($request === null) {
            return true;
        }

        return self::isLocalPeer(trim($request->getServer('remote_addr')))
            && self::forwardedFromHere($request)
            && self::isLocalName((string) $request->getHeader('Host'));
    }

    private static function isLocalPeer(string $peer): bool
    {
        if ($peer === '') {
            return true;
        }
        $peer = self::unmapped($peer);

        return self::isLoopback($peer) || ($peer === self::gateway() && $peer !== '');
    }

    /** Every address a forwarding header names is loopback or a Docker bridge gateway. */
    private static function forwardedFromHere(Request $request): bool
    {
        foreach (self::FORWARDING_HEADERS as $header) {
            $value = $request->getHeader($header);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            if ($header === 'Forwarded') {
                // `for` as a parameter of its own: `notfor=127.0.0.1` names no hop.
                preg_match_all('/(?:^|[;,])\s*for\s*=\s*"?\[?([^\]";,\s]+)/i', $value, $matches);
                $hops = $matches[1];
                if ($hops === []) {
                    return false;
                }
            } else {
                $hops = explode(',', $value);
            }
            foreach ($hops as $hop) {
                $address = self::unmapped(self::withoutPort(trim($hop)));
                if (!self::isLoopback($address) && !self::isDockerGateway($address) && !self::isConfiguredGateway($address)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isLocalName(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            // Rebinding needs a browser, and a browser always names the host;
            // a client without one (HTTP/1.0, a raw socket) is left to the peer.
            return true;
        }
        $name = self::withoutPort($host);
        if ($name === 'localhost' || str_ends_with($name, '.localhost') || str_ends_with($name, '.test')) {
            return true;
        }

        return self::isLoopback(self::unmapped($name));
    }

    private static function isLoopback(string $address): bool
    {
        if ($address === '::1') {
            return true;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($address, '127.');
    }

    /** x.y.0.1 in 172.16.0.0/12: the gateway Docker gives each network it numbers from its default pool. */
    private static function isDockerGateway(string $address): bool
    {
        $long = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ip2long($address) : false;

        return $long !== false && ($long & 0xFFF00000) === 0xAC100000 && ($long & 0xFFFF) === 1;
    }

    /** An address DEV_LOCAL_GATEWAYS names, compared by value (fd00::1 is fd00:0:0:0:0:0:0:1). */
    private static function isConfiguredGateway(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach (explode(',', (string) Environment::getEnvValue('DEV_LOCAL_GATEWAYS', '')) as $gateway) {
            if (@inet_pton(self::unmapped(trim($gateway))) === $packed) {
                return true;
            }
        }

        return false;
    }

    /** `::ffff:a.b.c.d` (an IPv4 client on a dual-stack listener) is a.b.c.d. */
    private static function unmapped(string $address): string
    {
        $address = strtolower($address);
        if (str_starts_with($address, '::ffff:') && filter_var(substr($address, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return substr($address, 7);
        }

        return $address;
    }

    /** `host:port`, `[::1]:443` and `[::1]` lose the port and the brackets. */
    private static function withoutPort(string $hostOrAddress): string
    {
        if (str_starts_with($hostOrAddress, '[')) {
            $end = strpos($hostOrAddress, ']');

            return $end === false ? $hostOrAddress : substr($hostOrAddress, 1, $end - 1);
        }

        return substr_count($hostOrAddress, ':') === 1 ? explode(':', $hostOrAddress, 2)[0] : $hostOrAddress;
    }

    /** The default route's gateway, '' when there is none to read (not Linux, no network). */
    private static function gateway(): string
    {
        if (self::$gateway !== null) {
            return self::$gateway;
        }
        self::$gateway = '';
        $lines = is_readable(self::$routeFile) ? (file(self::$routeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        foreach (array_slice($lines, 1) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (($fields[1] ?? '') === '00000000' && isset($fields[2]) && preg_match('/^[0-9A-Fa-f]{8}$/', $fields[2]) === 1) {
                // Little-endian hex: 010012AC is 172.18.0.1.
                self::$gateway = implode('.', array_reverse(array_map('hexdec', str_split($fields[2], 2))));
                break;
            }
        }

        return self::$gateway;
    }

    /** @internal for tests: read the route table from another file. */
    public static function useRouteFile(string $path): void
    {
        self::$routeFile = $path;
        self::$gateway = null;
    }
}
