<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\SecureCookieMode;
use Semitexa\Core\Http\SecureCookieSetting;

/**
 * The one reader of SESSION_COOKIE_SECURE, and the contract two callers depend
 * on: SessionPhase decides whether a cookie carries Secure, and
 * ForwardedProxyDoctorCheck tells an operator whether it will. They each had
 * their own list of spellings before this existed, and the lists had drifted —
 * doctor knew the enabling values and passed a deployment that had turned the
 * flag OFF over HTTPS.
 */
final class SecureCookieSettingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function enablingSpellings(): iterable
    {
        yield 'always' => ['always'];
        yield 'true' => ['true'];
        yield 'one' => ['1'];
        yield 'on' => ['on'];
        yield 'shouting' => ['ALWAYS'];
        yield 'padded' => ['  always  '];
    }

    /** @return iterable<string, array{string}> */
    public static function disablingSpellings(): iterable
    {
        yield 'never' => ['never'];
        yield 'false' => ['false'];
        yield 'zero' => ['0'];
        yield 'off' => ['off'];
    }

    #[Test]
    #[DataProvider('enablingSpellings')]
    public function an_enabling_value_forces_the_flag_on(string $value): void
    {
        $setting = SecureCookieSetting::fromValue($value);

        self::assertSame(SecureCookieMode::Always, $setting->mode);
        self::assertTrue($setting->recognised);
        self::assertTrue($setting->secureFor(true));
        self::assertTrue($setting->secureFor(false), 'always must survive a request that reads as http');
    }

    #[Test]
    #[DataProvider('disablingSpellings')]
    public function a_disabling_value_forces_the_flag_off(string $value): void
    {
        $setting = SecureCookieSetting::fromValue($value);

        self::assertSame(SecureCookieMode::Never, $setting->mode);
        self::assertTrue($setting->recognised);
        self::assertFalse($setting->secureFor(false));

        // The fact that makes the request-path warning gate correct: Never
        // ignores the detected scheme, so trusting the proxy could not have
        // restored the flag and the warning must not blame one.
        self::assertFalse($setting->secureFor(true), 'never must ignore the scheme, that is what it is for');
    }

    #[Test]
    public function unset_and_empty_and_auto_are_the_same_thing(): void
    {
        foreach ([null, '', '   ', 'auto', 'AUTO'] as $value) {
            $setting = SecureCookieSetting::fromValue($value);

            self::assertSame(SecureCookieMode::Auto, $setting->mode, var_export($value, true));
            self::assertTrue($setting->recognised);
            self::assertTrue($setting->secureFor(true));
            self::assertFalse($setting->secureFor(false));
        }
    }

    /**
     * `alway` behaved as auto, which on a site behind an untrusted proxy means
     * no Secure flag at all — silently honouring a typo is the failure this
     * setting exists to prevent. It is kept as a fact so each caller can say
     * what it does about it: the request path warns and carries on, doctor
     * fails.
     */
    #[Test]
    public function an_undefined_spelling_behaves_as_auto_but_says_so(): void
    {
        $setting = SecureCookieSetting::fromValue('alway');

        self::assertSame(SecureCookieMode::Auto, $setting->mode);
        self::assertFalse($setting->recognised);
        self::assertSame('alway', $setting->raw, 'the caller has to be able to quote it back');
    }

    #[Test]
    public function the_documented_values_are_listed_for_a_message_that_has_to_name_them(): void
    {
        $listed = SecureCookieSetting::documentedValues();

        foreach (['auto', 'always', 'true', '1', 'on', 'never', 'false', '0', 'off'] as $value) {
            self::assertStringContainsString($value, $listed);
        }
    }
}
