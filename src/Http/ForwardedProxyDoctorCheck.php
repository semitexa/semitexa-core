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
        $cookieSecure = strtolower(trim((string) (Environment::getEnvValue('SESSION_COOKIE_SECURE') ?? 'auto')));

        $declaresHttps = str_starts_with($appUrl, 'https://');

        if (in_array($cookieSecure, ['always', 'true', '1', 'on'], true)) {
            return DoctorResult::pass(
                'SESSION_COOKIE_SECURE=always — cookies carry Secure regardless of how the '
                . 'scheme is detected, so proxy trust cannot downgrade them.',
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
                . 'curl -sS -D - https://your-host/ | grep -i set-cookie — every line should say Secure.',
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
