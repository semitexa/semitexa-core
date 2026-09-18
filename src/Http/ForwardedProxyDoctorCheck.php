<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\DoctorResult;

/**
 * A deployment that calls itself HTTPS but will not believe a proxy that says so.
 *
 * Request::getScheme() reads X-Forwarded-Proto only from a peer it trusts —
 * loopback, or an address in TRUSTED_PROXIES. In the containerised topology the
 * project's own compose files ship, the reverse proxy is a sibling container on
 * a bridge network and is therefore NOT loopback, so the header is dropped, the
 * request reads as http, and SessionPhase sends the session and XSRF cookies
 * without Secure.
 *
 * core#102 recorded that failure mode and added TRUSTED_PROXIES as the remedy.
 * The remedy reached no shipped .env.default and nothing ever mentioned it, and
 * on 2026-09-18 semitexa.com was still answering HTTPS with:
 *   set-cookie: semitexa_session=...; path=/; HttpOnly; SameSite=lax
 *   set-cookie: XSRF-TOKEN=...;      path=/; SameSite=lax
 * Neither with Secure. A knob nobody can discover is not a fix, so this is the
 * thing that says it out loud — at `system:doctor` time, before a request has
 * to go wrong to find out.
 *
 * It reasons from configuration only, because doctor runs from a terminal and
 * has no request to look at. APP_URL declaring https is the deployment stating
 * its own intent; an empty TRUSTED_PROXIES with no loopback proxy is the
 * contradiction.
 */
#[AsDoctorCheck(name: 'http.forwarded-proxy-trust', package: 'semitexa/core')]
final class ForwardedProxyDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        $trusted = trim((string) (Environment::getEnvValue('TRUSTED_PROXIES') ?? ''));
        $appUrl = strtolower(trim((string) (Environment::getEnvValue('APP_URL') ?? '')));
        $cookieSecure = SecureCookieSetting::fromEnvironment();

        $declaresHttps = str_starts_with($appUrl, 'https://');

        if ($cookieSecure->mode === SecureCookieMode::Always) {
            return DoctorResult::pass(
                'SESSION_COOKIE_SECURE=always — cookies carry Secure regardless of how the '
                . 'scheme is detected, so proxy trust cannot downgrade them.',
            );
        }

        // A spelling this framework never defined behaves as auto, which is not
        // what the operator asked for and is invisible from the outside until a
        // cookie goes out unprotected. Reported wherever APP_URL points,
        // because the typo is wrong on its own terms.
        if (!$cookieSecure->recognised) {
            return DoctorResult::fail(
                sprintf(
                    'SESSION_COOKIE_SECURE is set to "%s", which is not a value this framework '
                    . 'defines. It falls back to auto — the scheme decides — so a deployment that '
                    . 'meant to force the Secure flag is not forcing it.',
                    $cookieSecure->raw,
                ),
                'Use one of: ' . SecureCookieSetting::documentedValues() . '.',
            );
        }

        // THE HOLE THIS CHECK WAS LEAVING. It knew the enabling spellings and
        // said nothing about the disabling ones, so an HTTPS deployment with
        // SESSION_COOKIE_SECURE=never passed on the strength of a populated
        // TRUSTED_PROXIES while every cookie went out without Secure — the
        // exact answer this check exists to give, given confidently and wrong.
        if ($declaresHttps && $cookieSecure->mode === SecureCookieMode::Never) {
            return DoctorResult::fail(
                sprintf(
                    'APP_URL is https but SESSION_COOKIE_SECURE=%s turns the Secure flag OFF, so the '
                    . 'session and XSRF cookies go out over HTTPS without it. Proxy trust cannot '
                    . 'override an explicit setting, so TRUSTED_PROXIES does not help here.',
                    $cookieSecure->raw,
                ),
                'Set SESSION_COOKIE_SECURE=always for an HTTPS deployment, or auto to let the request '
                . 'decide. The disabling values are for a deployment that really is plain HTTP. Confirm '
                . 'with curl -sS -D - -o /dev/null https://your-host/ | grep -i \'^set-cookie:\' — every line should say Secure, and no output at all means no cookie was set, which is not a pass.',
            );
        }

        if (!$declaresHttps) {
            return DoctorResult::pass(
                $appUrl === ''
                    ? 'APP_URL is unset, so there is nothing to check proxy trust against. Set it '
                      . 'to the URL this deployment answers on to make this check meaningful.'
                    : 'APP_URL is not https, so an untrusted X-Forwarded-Proto cannot cost a Secure cookie.',
            );
        }

        if ($trusted === '') {
            return DoctorResult::fail(
                'APP_URL is https but TRUSTED_PROXIES is empty. Only a loopback peer is trusted to '
                . 'set X-Forwarded-Proto, so a reverse proxy anywhere else — the sibling container '
                . 'in this project\'s own compose files, for one — is ignored, the request reads as '
                . 'http, and the session and XSRF cookies go out WITHOUT Secure.',
                'Set TRUSTED_PROXIES to the proxy address or CIDR (e.g. TRUSTED_PROXIES=172.18.0.0/16), '
                . 'or, if every route to this app is HTTPS, SESSION_COOKIE_SECURE=always. Confirm with '
                . 'curl -sS -D - -o /dev/null https://your-host/ | grep -i \'^set-cookie:\' — every line should say Secure, and no output at all means no cookie was set, which is not a pass.',
            );
        }

        $entries = substr_count($trusted, ',') + 1;

        return DoctorResult::pass(sprintf(
            'APP_URL is https and TRUSTED_PROXIES names %d entr%s, so a proxy behind one of them '
            . 'can set the scheme.',
            $entries,
            $entries === 1 ? 'y' : 'ies',
        ));
    }
}
