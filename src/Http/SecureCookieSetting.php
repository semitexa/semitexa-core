<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Environment;

/**
 * What `SESSION_COOKIE_SECURE` was set to, read once and understood in one place.
 *
 * Two things read this variable — {@see \Semitexa\Core\Lifecycle\SessionPhase},
 * which decides whether the cookies carry `Secure`, and
 * {@see ForwardedProxyDoctorCheck}, which tells an operator whether they will.
 * They each had their own list of accepted spellings, and the lists had already
 * drifted: doctor knew the enabling values and passed the deployment either way,
 * while a disabling value on an HTTPS site sent every cookie without `Secure`
 * and doctor still said Pass. A setting understood in two places is a setting
 * understood in neither.
 *
 * ## An unrecognised value is its own state
 *
 * `SESSION_COOKIE_SECURE=alway` is not `auto`, even though it behaves like it.
 * The typo silently disabled the flag on a deployment that had asked for it,
 * which is the failure this setting was introduced to end. So a spelling nobody
 * declared is kept as a fact — `recognised` — and each caller says what it does
 * about it: the request path warns once per worker and falls back to `auto`
 * rather than refusing to boot over a typo; doctor fails, because telling an
 * operator is the entire job it has.
 */
final readonly class SecureCookieSetting
{
    /** Spellings that force the flag on, whatever the scheme looks like. */
    private const ALWAYS = ['always', 'true', '1', 'on'];

    /** Spellings that force it off — for a deployment that really is plain HTTP. */
    private const NEVER = ['never', 'false', '0', 'off'];

    private const AUTO = 'auto';

    private function __construct(
        /** The value as configured, lowercased and trimmed; '' when unset. */
        public string $raw,
        public SecureCookieMode $mode,
        /** False when the value is a spelling this framework never defined. */
        public bool $recognised,
    ) {}

    public static function fromEnvironment(): self
    {
        return self::fromValue(Environment::getEnvValue('SESSION_COOKIE_SECURE'));
    }

    public static function fromValue(?string $value): self
    {
        $raw = strtolower(trim((string) $value));

        if ($raw === '' || $raw === self::AUTO) {
            return new self($raw, SecureCookieMode::Auto, true);
        }

        if (in_array($raw, self::ALWAYS, true)) {
            return new self($raw, SecureCookieMode::Always, true);
        }

        if (in_array($raw, self::NEVER, true)) {
            return new self($raw, SecureCookieMode::Never, true);
        }

        // Treated as auto so a typo cannot take a deployment down, and marked
        // so that the callers can say it out loud instead.
        return new self($raw, SecureCookieMode::Auto, false);
    }

    /**
     * Does a cookie get the Secure flag, given what this request looks like?
     *
     * `$isHttps` is the request's own answer, which is already the product of
     * proxy trust — an untrusted `X-Forwarded-Proto: https` reads as http here,
     * and that is exactly the case `always` exists to override.
     */
    public function secureFor(bool $isHttps): bool
    {
        return match ($this->mode) {
            SecureCookieMode::Always => true,
            SecureCookieMode::Never => false,
            SecureCookieMode::Auto => $isHttps,
        };
    }

    /** The spellings an operator may write, for a message that has to list them. */
    public static function documentedValues(): string
    {
        return implode(', ', [self::AUTO, ...self::ALWAYS, ...self::NEVER]);
    }
}
