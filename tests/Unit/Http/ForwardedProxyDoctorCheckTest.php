<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\ForwardedProxyDoctorCheck;
use Semitexa\Core\Support\DoctorStatus;

/**
 * The check exists because the knob it points at was already there and nobody
 * could find it: core#102 added TRUSTED_PROXIES as the remedy for a proxy off
 * loopback, the remedy reached no shipped .env.default, and on 2026-09-18
 * semitexa.com was still answering HTTPS with cookies that had no Secure flag.
 *
 * The case that matters most is the FAILING one, so it is first and it is
 * written as the production configuration actually was.
 */
final class ForwardedProxyDoctorCheckTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['TRUSTED_PROXIES', 'APP_URL', 'SESSION_COOKIE_SECURE'] as $name) {
            $this->saved[$name] = getenv($name);
            // An EMPTY process value, not an unset one. putenv($name) removes
            // the process entry and Environment::getEnvValue() then falls
            // through to its once-per-process cache of .env / .env.default — so
            // a developer with the variable set in .env ran a different test
            // from CI, silently. Empty is what "not configured" means to every
            // reader here.
            putenv($name . '=');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    #[Test]
    public function an_https_deployment_with_no_trusted_proxies_fails(): void
    {
        putenv('APP_URL=https://semitexa.com');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status, 'this is the configuration production was in');
        self::assertStringContainsString('TRUSTED_PROXIES', $result->message);
        self::assertStringContainsString('Secure', $result->message);
    }

    #[Test]
    public function the_failure_says_what_to_do_and_how_to_confirm_it(): void
    {
        putenv('APP_URL=https://semitexa.com');

        $result = (new ForwardedProxyDoctorCheck())->run();

        // A check that reports a problem without a remedy sends the reader back
        // to the source to work out what it wanted.
        self::assertNotNull($result->hint);
        self::assertStringContainsString('TRUSTED_PROXIES=', $result->hint);
        self::assertStringContainsString('SESSION_COOKIE_SECURE=always', $result->hint);
        self::assertStringContainsString('set-cookie', $result->hint);
    }

    #[Test]
    public function naming_the_proxy_settles_it(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=172.18.0.0/16');

        self::assertSame(DoctorStatus::Pass, (new ForwardedProxyDoctorCheck())->run()->status);
    }

    #[Test]
    public function forcing_the_flag_settles_it_without_touching_proxy_trust(): void
    {
        // The answer for a deployment that cannot enumerate its proxies — a CDN
        // with rotating addresses — but knows every route to it is HTTPS.
        putenv('APP_URL=https://semitexa.com');
        putenv('SESSION_COOKIE_SECURE=always');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Pass, $result->status);
        self::assertStringContainsString('regardless', $result->message);
    }

    #[Test]
    public function a_plain_http_deployment_has_nothing_to_lose(): void
    {
        putenv('APP_URL=http://localhost:9502');

        self::assertSame(DoctorStatus::Pass, (new ForwardedProxyDoctorCheck())->run()->status);
    }

    #[Test]
    public function an_unset_app_url_passes_but_says_the_check_is_toothless(): void
    {
        // Passing quietly here would be the same silence the check exists to
        // break: it must say that it could not actually check anything.
        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Pass, $result->status);
        self::assertStringContainsString('APP_URL is unset', $result->message);
    }

    /**
     * THE HOLE THIS CHECK WAS LEAVING. It knew the enabling spellings of
     * SESSION_COOKIE_SECURE and said nothing about the disabling ones, so an
     * HTTPS deployment that had turned the flag OFF passed on the strength of a
     * populated TRUSTED_PROXIES — while every cookie went out over HTTPS
     * without Secure. Proxy trust cannot override an explicit setting.
     */
    #[Test]
    public function an_https_deployment_that_turns_the_flag_off_fails_even_with_trusted_proxies(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=172.18.0.0/16');
        putenv('SESSION_COOKIE_SECURE=never');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status);
        self::assertStringContainsString('never', $result->message);
        self::assertStringContainsString('TRUSTED_PROXIES does not help', $result->message);
        self::assertStringContainsString('SESSION_COOKIE_SECURE=always', (string) $result->hint);
    }

    /** Every disabling spelling, not just the word. */
    #[Test]
    public function the_other_disabling_spellings_fail_the_same_way(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=172.18.0.0/16');

        foreach (['false', '0', 'off'] as $value) {
            putenv('SESSION_COOKIE_SECURE=' . $value);

            self::assertSame(
                DoctorStatus::Fail,
                (new ForwardedProxyDoctorCheck())->run()->status,
                $value . ' turns the Secure flag off and must be reported',
            );
        }
    }

    /**
     * A spelling nobody defined behaves as auto — which is not what the
     * operator asked for, and is invisible from outside until a cookie goes out
     * unprotected.
     */
    #[Test]
    public function an_undefined_spelling_is_reported_rather_than_treated_as_auto(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=172.18.0.0/16');
        putenv('SESSION_COOKIE_SECURE=alway');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status);
        self::assertStringContainsString('alway', $result->message);
        self::assertStringContainsString('always', (string) $result->hint, 'the remedy lists what is accepted');
    }

    /** And a plain-HTTP deployment that disables the flag is doing what it says. */
    #[Test]
    public function a_plain_http_deployment_may_turn_the_flag_off(): void
    {
        putenv('APP_URL=http://localhost:9502');
        putenv('SESSION_COOKIE_SECURE=never');

        self::assertSame(DoctorStatus::Pass, (new ForwardedProxyDoctorCheck())->run()->status);
    }

    /**
     * COUNTING THE ENTRIES WAS NOT READING THEM. `not-an-ip,172.18.0.0/99` is
     * two entries and trusts nobody — Request::ipMatchesEntry() rejects both,
     * X-Forwarded-Proto keeps being dropped — and this check called it healthy
     * on the strength of a comma.
     */
    #[Test]
    public function entries_that_can_never_match_a_peer_are_not_proxy_trust(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=not-an-ip,172.18.0.0/99');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status);
        self::assertStringContainsString('not-an-ip', $result->message);
        self::assertStringContainsString('172.18.0.0/99', $result->message);
        self::assertStringContainsString('WITHOUT Secure', $result->message);
    }

    /** A hostname is the one people reach for, and it matches nothing. */
    #[Test]
    public function a_hostname_is_not_an_entry(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=proxy.internal');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Fail, $result->status);
        self::assertStringContainsString('hostname', (string) $result->hint);
    }

    /**
     * A list that half works is a warning, not a failure: the good entry still
     * trusts its proxy. It is worth saying, because the inert half is what
     * makes somebody delete the working entry later.
     */
    #[Test]
    public function a_partly_usable_list_warns_and_names_the_inert_entries(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=172.18.0.7,nonsense');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Warn, $result->status);
        self::assertStringContainsString('nonsense', $result->message);
        self::assertStringNotContainsString('172.18.0.7', $result->message, 'the working entry is not the problem');
    }

    #[Test]
    public function a_usable_list_passes_and_says_how_many(): void
    {
        putenv('APP_URL=https://semitexa.com');
        putenv('TRUSTED_PROXIES=172.18.0.7, 2001:db8::/32');

        $result = (new ForwardedProxyDoctorCheck())->run();

        self::assertSame(DoctorStatus::Pass, $result->status);
        self::assertStringContainsString('2 usable entries', $result->message);
    }
}