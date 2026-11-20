<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Environment;

/**
 * What the server says at start when it will run without APP_SECRET outside dev
 * and test. A warning, not a refusal: {@see AppSecret::current()} is read at first
 * use, so a project that signs nothing runs without one, and a refusal would turn
 * a reboot into an outage. Without the line the first sign of a missing secret is
 * a 500 on whichever page signs something first.
 *
 * @internal Printed once by the master in SwooleBootstrap::run().
 */
final class AppSecretStartWarning
{
    /** The warning for the environment this process runs in, resolved as the doctor check resolves it. */
    public static function current(): ?string
    {
        return self::message(
            strtolower(trim((string) Environment::getEnvValue('APP_ENV', 'prod'))),
            (string) Environment::getEnvValue('APP_SECRET', ''),
        );
    }

    /**
     * The text to print, or null when there is nothing to warn about: a secret is
     * set, or APP_ENV is dev or test, where AppSecret uses a stand-in.
     *
     * @param string $appEnv APP_ENV, trimmed and lower-cased
     */
    public static function message(string $appEnv, string $secret): ?string
    {
        if ($secret !== '' || in_array($appEnv, ['dev', 'test'], true)) {
            return null;
        }

        return implode("\n", [
            '[Semitexa] ==================== APP_SECRET IS NOT SET ====================',
            '[Semitexa] APP_ENV=' . $appEnv . ' has no stand-in for it. The server starts, but signed components,',
            '[Semitexa] encrypted values and signed links each fail with a 500 at their first use.',
            '[Semitexa] Fix: set APP_SECRET in .env to a long random value, for example: openssl rand -hex 32',
            '[Semitexa] Then restart the server. bin/semitexa system:doctor reports the same (check app.secret).',
            '[Semitexa] ================================================================',
        ]);
    }
}
