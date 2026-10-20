<?php

declare(strict_types=1);

namespace Semitexa\Core\Security;

use Semitexa\Core\Environment;

/**
 * The application's secret, and the keys derived from it.
 *
 * APP_SECRET is required outside dev and test; there, a value derived from
 * APP_NAME, APP_HOST and APP_PORT stands in, so a fresh checkout works and
 * nothing it signs is trusted anywhere else. APP_SECRET_PREVIOUS (comma
 * separated) lists secrets that are being rotated out: what they signed or
 * encrypted is still accepted, nothing new is made with them.
 *
 * Every use gets its own key, derived per purpose, so a signature made for one
 * thing never verifies as another.
 */
final class AppSecret
{
    public static function current(): string
    {
        $explicit = Environment::getEnvValue('APP_SECRET');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $appEnv = strtolower(trim((string) Environment::getEnvValue('APP_ENV', 'prod')));
        if (!in_array($appEnv, ['dev', 'test'], true)) {
            throw new \LogicException('APP_SECRET must be set outside dev and test: it signs and encrypts what the application hands out.');
        }

        return hash('sha256', implode('|', [
            (string) Environment::getEnvValue('APP_NAME', 'Semitexa'),
            (string) Environment::getEnvValue('APP_HOST', 'localhost'),
            (string) Environment::getEnvValue('APP_PORT', '8000'),
        ]));
    }

    /** @return list<string> */
    public static function previous(): array
    {
        $raw = (string) Environment::getEnvValue('APP_SECRET_PREVIOUS', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    /** A 32-byte key for one purpose, from one secret. */
    public static function derive(string $secret, string $purpose): string
    {
        return hash_hmac('sha256', 'semitexa.' . $purpose, $secret, true);
    }
}
