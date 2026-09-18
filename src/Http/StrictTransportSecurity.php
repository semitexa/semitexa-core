<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Environment;

/**
 * The Strict-Transport-Security header, off unless the deployment asks for it.
 *
 * OFF BY DEFAULT, and that is not timidity. HSTS is one of the few headers a
 * server cannot take back UNCONDITIONALLY: max-age=0 over working HTTPS does
 * withdraw it, but that withdrawal only arrives over a connection the browser
 * will still make. A browser that has seen max-age=31536000 refuses plaintext to
 * that host for a year, from its own cache — so a consumer whose certificate
 * later lapses has no HTTPS left to carry the rollback and no plaintext fallback
 * to fall back to, and is locked out of their own site until the certificate is
 * valid again. A framework may not make that choice on a consumer's behalf.
 *
 * NOT GATED ON THE REQUEST SCHEME, and that IS a deliberate lesson. The obvious
 * design is "send it only when the request is https", which reads as careful
 * and would have reproduced the defect this was filed alongside: on 2026-09-18
 * production's scheme detection said http — an untrusted proxy — so a
 * scheme-gated header would simply never have gone out, silently, exactly as
 * the session cookie silently lost Secure. Configuring HSTS IS the deployment
 * stating it is HTTPS, so the configuration is the signal. RFC 6797 §8.1 makes
 * this safe from the other side: a user agent MUST ignore the header when it
 * arrives over anything but a secure transport.
 *
 * `preload` is separate from `includeSubDomains` for the same reason the whole
 * thing is opt-in. Submitting to the browsers' preload list is effectively
 * permanent — removal takes months and is not guaranteed — so it cannot ride
 * along on a subdomain setting.
 */
final class StrictTransportSecurity
{
    public const HEADER = 'Strict-Transport-Security';

    /**
     * The header value, or null when HSTS is off.
     *
     * Off means HSTS_MAX_AGE unset, empty, zero or unparseable. A garbage value
     * turns the header OFF rather than guessing a duration: guessing here would
     * pick a number nobody chose, and the one thing that must not happen is a
     * max-age the operator did not intend.
     */
    /**
     * The configured max-age in seconds, or null when there is no usable one.
     *
     * The single parser, because the doctor check has to reach the same verdict
     * as the header builder — it already re-derived "is this a positive number"
     * with its own `ctype_digit`, and two parsers of one value is how a doctor
     * comes to bless a header the server will not send.
     *
     * OUT OF RANGE IS NOT A DURATION. `ctype_digit` accepts a number wider than
     * the platform's integer, and the `(int)` cast then SATURATES it: an
     * operator who typed twenty digits got max-age=9223372036854775807 — about
     * 292 billion years, cached and honoured by every browser that saw it — and
     * a doctor check that called the value valid. Refused for the same reason
     * garbage is refused: the one outcome that must not happen with this header
     * is a duration nobody chose.
     */
    public static function configuredMaxAge(): ?int
    {
        $raw = trim((string) (Environment::getEnvValue('HSTS_MAX_AGE') ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        // Leading zeros are the operator's formatting, not a different number.
        $digits = ltrim($raw, '0');
        if ($digits === '') {
            return null; // 0, 00, 000 — off, as documented
        }

        $maxAge = (int) $digits;
        // The round trip is the range check: a value the platform cannot hold
        // comes back as something other than what was written.
        if ((string) $maxAge !== $digits || $maxAge <= 0) {
            return null;
        }

        return $maxAge;
    }

    public static function headerValue(): ?string
    {
        $maxAge = self::configuredMaxAge();
        if ($maxAge === null) {
            return null;
        }

        $value = 'max-age=' . $maxAge;

        if (self::flag('HSTS_INCLUDE_SUBDOMAINS')) {
            $value .= '; includeSubDomains';
        }

        // preload is meaningless to the browsers' list without includeSubDomains
        // and a max-age of at least a year, but this does not silently add
        // either: a header that quietly widened its own scope to every subdomain
        // is the opposite of what an opt-in is for. It is emitted as asked, and
        // system:doctor says when the combination will be rejected.
        if (self::flag('HSTS_PRELOAD')) {
            $value .= '; preload';
        }

        return $value;
    }

    private static function flag(string $name): bool
    {
        $raw = strtolower(trim((string) (Environment::getEnvValue($name) ?? '')));

        return $raw === '1' || $raw === 'true' || $raw === 'on' || $raw === 'yes';
    }
}
