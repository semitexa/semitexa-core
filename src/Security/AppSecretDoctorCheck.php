<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\DoctorResult;

/**
 * Whether APP_SECRET is there before anything needs it. Outside dev and test a
 * missing one fails each signed component, encrypted value and signed link at
 * its first use, as a 500 — usually found after the switch to APP_ENV=prod.
 */
#[AsDoctorCheck(name: 'app.secret', package: 'semitexa/core')]
final class AppSecretDoctorCheck implements DoctorCheckInterface
{
    private const MIN_LENGTH = 32;

    public function run(): DoctorResult
    {
        return self::verdict(
            strtolower(trim((string) Environment::getEnvValue('APP_ENV', 'prod'))),
            (string) Environment::getEnvValue('APP_SECRET', ''),
        );
    }

    public static function verdict(string $appEnv, string $secret): DoctorResult
    {
        $development = in_array($appEnv, ['dev', 'test'], true);

        return match (true) {
            $secret === '' && $development => DoctorResult::pass(
                'APP_SECRET is not set; APP_ENV=' . $appEnv . ' uses a stand-in derived from the app name and address. Set one before going to prod.',
            ),
            $secret === '' => DoctorResult::fail(
                'APP_SECRET is not set: signed components, encrypted values and signed links fail with 500 under APP_ENV=' . $appEnv . '.',
                'Set APP_SECRET in .env to a long random value: openssl rand -hex 32',
            ),
            strlen($secret) < self::MIN_LENGTH => DoctorResult::warn(
                'APP_SECRET is ' . strlen($secret) . ' characters: short enough to guess offline from one signed link.',
                'Use at least ' . self::MIN_LENGTH . ' random characters: openssl rand -hex 32. Keep the old one in APP_SECRET_PREVIOUS while links signed with it are still out.',
            ),
            default => DoctorResult::pass('APP_SECRET is set.'),
        };
    }
}
