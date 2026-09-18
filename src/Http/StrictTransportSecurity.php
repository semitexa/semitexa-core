<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Environment;

/**
 * The Strict-Transport-Security header, off unless the deployment asks for it.
 *
 * OFF BY DEFAULT, and that is not timidity. HSTS is one of the few headers a
 * server cannot take back: a browser that has seen max-age=31536000 refuses
 * plaintext to that host for a year, from its own cache, whatever the server
 * says afterwards. A consumer whose certificate later lapses is locked out of
 * their own site and cannot fix it by changing anything they control. A
 * framework may not make that choice on a consumer's behalf.
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
    public static function headerValue(): ?string
    {
        $raw = trim((string) (Environment::getEnvValue('HSTS_MAX_AGE') ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $maxAge = (int) $raw;
        if ($maxAge <= 0) {
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
