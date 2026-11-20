<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Security\AppSecretDoctorCheck;
use Semitexa\Core\Security\AppSecretStartWarning;

/**
 * tk-new-required-setting-reaches-prod-silently: on 20.11 a consumer's prod served
 * 500 on every signed component because nothing said APP_SECRET was missing until a
 * page needed it. The server now says so at start.
 */
final class AppSecretStartWarningTest extends TestCase
{
    #[Test]
    public function prod_without_a_secret_is_told_what_breaks_and_how_to_fix_it(): void
    {
        $warning = AppSecretStartWarning::message('prod', '');

        self::assertIsString($warning);
        self::assertStringContainsString('APP_SECRET', $warning);
        self::assertStringContainsString('APP_ENV=prod', $warning);
        self::assertStringContainsString('signed components', $warning);
        self::assertStringContainsString('encrypted values', $warning);
        self::assertStringContainsString('signed links', $warning);
        self::assertStringContainsString('500', $warning);
        self::assertStringContainsString('.env', $warning);
        self::assertStringContainsString('openssl rand -hex 32', $warning);
        foreach (explode("\n", $warning) as $line) {
            self::assertStringStartsWith('[Semitexa] ', $line, 'every line is findable in a shared log');
        }
    }

    #[Test]
    public function the_env_judged_is_the_one_named(): void
    {
        self::assertStringContainsString('APP_ENV=staging', (string) AppSecretStartWarning::message('staging', ''));
    }

    #[Test]
    public function dev_test_and_a_set_secret_are_silent(): void
    {
        self::assertNull(AppSecretStartWarning::message('test', ''));
        self::assertNull(AppSecretStartWarning::message('dev', ''));
        self::assertNull(AppSecretStartWarning::message('prod', str_repeat('a1', 32)));
        self::assertNull(AppSecretStartWarning::message('prod', 'short'), 'a short secret is the doctor\'s warning, not a start alarm');
    }

    /** The start warning and the doctor's FAIL are one rule: neither may drift from the other. */
    #[Test]
    public function it_warns_exactly_where_the_doctor_check_fails(): void
    {
        foreach (['prod', 'staging', 'dev', 'test', ''] as $appEnv) {
            foreach (['', 'short', str_repeat('a1', 32)] as $secret) {
                self::assertSame(
                    AppSecretDoctorCheck::verdict($appEnv, $secret)->status->value === 'fail',
                    AppSecretStartWarning::message($appEnv, $secret) !== null,
                    "APP_ENV={$appEnv}, secret of " . strlen($secret) . ' characters',
                );
            }
        }
    }
}
