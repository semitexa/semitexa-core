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
            putenv($name);
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
}
