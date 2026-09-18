<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\DoctorResult;

/**
 * Whether HSTS is on, and whether the combination asked for will be honoured.
 *
 * Deliberately a WARN and never a FAIL when HSTS is simply off. It is optional,
 * it is irreversible once a browser has seen it, and a framework that failed
 * the doctor over a header a consumer may rationally not want would teach
 * people to stop reading the doctor. What it will not do is stay silent: the
 * framework emitted no HSTS at all before 2026-09-18, semitexa.com redirects
 * http to https, and that redirect is the request HSTS exists to remove.
 */
#[AsDoctorCheck(name: 'http.strict-transport-security', package: 'semitexa/core')]
final class StrictTransportSecurityDoctorCheck implements DoctorCheckInterface
{
    /** The browsers' preload list requires at least one year. */
    private const PRELOAD_MIN_MAX_AGE = 31536000;

    public function run(): DoctorResult
    {
        $value = StrictTransportSecurity::headerValue();
        $appUrl = strtolower(trim((string) (Environment::getEnvValue('APP_URL') ?? '')));
        $raw = trim((string) (Environment::getEnvValue('HSTS_MAX_AGE') ?? ''));

        if ($value === null) {
            // The same parser the header builder uses, rather than a second
            // opinion about the same string. The second opinion was wrong for a
            // number wider than the platform's integer: ctype_digit accepted it
            // and the (int) cast saturated, so this branch was skipped and the
            // check went on to report HSTS as simply "off".
            if ($raw !== '' && StrictTransportSecurity::configuredMaxAge() === null) {
                return DoctorResult::fail(
                    sprintf('HSTS_MAX_AGE is set to "%s", which is not a positive number of seconds this '
                        . 'platform can hold, so no Strict-Transport-Security header is sent at all.', $raw),
                    'Set it to a duration in seconds (HSTS_MAX_AGE=31536000 is one year), or remove it. '
                    . 'It is deliberately not guessed: a max-age nobody chose is the one outcome that '
                    . 'must not happen with a header browsers cache and honour for its full duration.',
                );
            }

            if (!str_starts_with($appUrl, 'https://')) {
                return DoctorResult::pass(
                    'HSTS is off, and APP_URL is not https, so there is nothing for it to protect.',
                );
            }

            return DoctorResult::warn(
                'APP_URL is https but no Strict-Transport-Security header is sent, so a visitor who '
                . 'types the bare hostname makes one plaintext request before the redirect every time.',
                'HSTS_MAX_AGE=31536000 turns it on. Read this before you do: a browser that has seen '
                . 'the header refuses plaintext to this host for the full duration from its own cache. '
                . 'It can be withdrawn — HSTS_MAX_AGE=0 sends max-age=0 and browsers forget — but that '
                . 'withdrawal only travels over working HTTPS, so a lapsed certificate leaves nothing to '
                . 'carry it and locks visitors out until the certificate is valid again. Start short — '
                . 'HSTS_MAX_AGE=300 — confirm with '
                . 'curl -sS -D - -o /dev/null https://your-host/ | grep -i \'^strict-transport-security:\' '
                . 'and raise it once you trust the renewal.',
            );
        }

        // A withdrawal is a state of its own, and a deliberate one: the header
        // IS being sent, and what it says is "forget this host". Reporting it
        // as an ordinary configuration would hide the one moment when an
        // operator most wants confirmation that the rollback is going out.
        if (StrictTransportSecurity::configuredMaxAge() === 0) {
            return DoctorResult::pass(
                'HSTS_MAX_AGE=0 — Strict-Transport-Security: max-age=0 is being sent, which tells every '
                . 'browser that reaches this site over working HTTPS to forget the policy. Leave it in '
                . 'place until the longest max-age previously sent has expired for your visitors; '
                . 'removing the variable instead stops sending the withdrawal and the old policy stands.',
            );
        }

        $preload = str_contains($value, 'preload');
        $includesSubdomains = str_contains($value, 'includeSubDomains');
        $maxAge = (int) $raw;

        if ($preload) {
            $problems = [];
            if (!$includesSubdomains) {
                $problems[] = 'includeSubDomains is missing';
            }
            if ($maxAge < self::PRELOAD_MIN_MAX_AGE) {
                $problems[] = sprintf('max-age is %d, below the required %d', $maxAge, self::PRELOAD_MIN_MAX_AGE);
            }

            if ($problems !== []) {
                return DoctorResult::fail(
                    'HSTS_PRELOAD is on but the policy will be rejected by the preload list: '
                    . implode(', ', $problems) . '.',
                    'Set HSTS_INCLUDE_SUBDOMAINS=true and HSTS_MAX_AGE=31536000, or turn HSTS_PRELOAD off. '
                    . 'Neither is added for you — a header that quietly widened its own scope to every '
                    . 'subdomain is the opposite of what an opt-in is for.',
                );
            }

            return DoctorResult::warn(
                sprintf('HSTS is on and asks for preloading: %s', $value),
                'Preloading is effectively permanent — removal from the browsers\' list takes months and '
                . 'is not guaranteed. Every current and future subdomain must serve valid HTTPS, forever. '
                . 'Keep this only if that is true.',
            );
        }

        return DoctorResult::pass(sprintf('HSTS is on: %s', $value));
    }
}
