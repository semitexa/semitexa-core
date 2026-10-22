<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Environment;

/**
 * Which peers may speak for the client in forwarded headers.
 *
 * Loopback is always trusted — a proxy on the same host. Anything else only
 * when the operator lists it in TRUSTED_PROXIES (comma-separated IPs and/or
 * CIDR blocks, e.g. `172.18.0.0/16, 10.0.0.5`). The default stays loopback
 * only: trusting private ranges implicitly would let any container on the
 * bridge spoof the scheme or the client address. One reader for
 * Request::isTrustedForwardedRequest() and ClientIp, so the two cannot drift.
 */
final class TrustedProxies
{
    public static function trusts(string $address): bool
    {
        $address = strtolower(trim($address));

        if ($address === '127.0.0.1' || $address === '::1' || $address === 'localhost') {
            return true;
        }

        foreach (explode(',', (string) Environment::getEnvValue('TRUSTED_PROXIES', '')) as $entry) {
            $entry = trim($entry);
            if ($entry !== '' && self::matches($address, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Could this TRUSTED_PROXIES entry ever match anything?
     *
     * The shape check on its own, so a diagnostic can ask the question without
     * inventing a second parser: `not-an-ip` and `172.18.0.0/99` are silently
     * inert here — they match no peer, ever — and a doctor check that only
     * counted the entries reported such a list as healthy while the app went on
     * dropping X-Forwarded-Proto.
     *
     * Shape only. Whether the entry names the RIGHT network is a question
     * nothing but the deployment can answer.
     */
    public static function isUsableEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return @inet_pton($entry) !== false;
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        $subnetBin = @inet_pton(trim($subnet));
        if ($subnetBin === false || !ctype_digit(trim($bits))) {
            return false;
        }

        $bits = (int) trim($bits);

        return $bits >= 0 && $bits <= strlen($subnetBin) * 8;
    }

    /** One TRUSTED_PROXIES entry — a bare IP or a CIDR block, IPv4 or IPv6. */
    public static function matches(string $ip, string $entry): bool
    {
        // One reader of what an entry may look like, shared with the doctor
        // check that reports on it.
        if (!self::isUsableEntry($entry)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        // A dual-stack listener reports an IPv4 peer as ::ffff:a.b.c.d, which
        // never matched an IPv4 entry: the proxy was not trusted.
        if (strlen($ipBin) === 16 && str_starts_with($ipBin, str_repeat("\0", 10) . "\xff\xff") && !str_contains($entry, ':')) {
            $ipBin = substr($ipBin, 12);
        }

        if (!str_contains($entry, '/')) {
            $entryBin = @inet_pton($entry);

            return $entryBin !== false && $entryBin === $ipBin;
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        $subnetBin = @inet_pton(trim($subnet));
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin) || !ctype_digit(trim($bits))) {
            return false;
        }
        $bits = (int) trim($bits);
        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return ((ord($ipBin[$fullBytes]) ^ ord($subnetBin[$fullBytes])) & $mask) === 0;
    }
}
