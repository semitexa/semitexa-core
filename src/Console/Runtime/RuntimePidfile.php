<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

/**
 * Pidfile and identity-cookie helpers shared by reload/stop runtime actions.
 *
 * The pidfile on disk only carries the numeric PID. Once the master exits, the kernel
 * may recycle that PID to an unrelated process — so signalling based on the pidfile
 * alone is unsafe. This helper pairs the pidfile with a sidecar cookie written at
 * server Start containing the absolute path of the bootstrapping script, and
 * verifies the live process cmdline before any signal is delivered.
 *
 * Fallback: when the cookie is absent (older builds, tmpfs loss), identity is checked
 * via anchored basename match on /proc/PID/cmdline fields rather than substring.
 */
final class RuntimePidfile
{
    public const COOKIE_RELATIVE_PATH = '/var/run/semitexa.cookie';

    /**
     * Write the identity cookie alongside Swoole's own pid_file. Best-effort: a
     * failure here degrades identity verification to the basename-anchored fallback
     * but never prevents startup.
     */
    public static function writeCookie(string $projectRoot, int $pid, string $serverScriptPath): void
    {
        $dir = $projectRoot . '/var/run';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $serverScriptPath = self::normalizeScriptPath($serverScriptPath, $projectRoot);

        $payload = sprintf("%d\n%s\n", $pid, $serverScriptPath);
        @file_put_contents($projectRoot . self::COOKIE_RELATIVE_PATH, $payload, LOCK_EX);
    }

    /**
     * @return array{pid:int,script:string}|null
     */
    public static function readCookie(string $projectRoot): ?array
    {
        $path = $projectRoot . self::COOKIE_RELATIVE_PATH;
        if (!is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $contents)),
            static fn (string $line): bool => $line !== '',
        ));
        if (count($lines) < 2) {
            return null;
        }

        $pid = filter_var($lines[0], FILTER_VALIDATE_INT);
        if ($pid === false || $pid <= 0) {
            return null;
        }

        return ['pid' => $pid, 'script' => $lines[1]];
    }

    /**
     * Verify that $pid belongs to the Swoole master booted from $projectRoot.
     *
     * Identity is matched against the recorded server-script absolute path from the
     * cookie sidecar; when the cookie is missing, falls back to an anchored basename
     * check for 'server.php' on any NUL-separated cmdline field.
     */
    public static function verifyProcess(int $pid, string $projectRoot): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (!function_exists('posix_kill') || !@posix_kill($pid, 0)) {
            return false;
        }

        $fields = self::readCmdlineFields($pid);
        if ($fields === null) {
            return false;
        }

        $cookie = self::readCookie($projectRoot);
        if ($cookie !== null && $cookie['script'] !== '') {
            $cookieScript = self::normalizeScriptPath($cookie['script'], $projectRoot);
            foreach ($fields as $field) {
                $normalizedField = self::normalizeScriptPath($field, $projectRoot);
                if (
                    $field === $cookie['script']
                    || ($normalizedField !== '' && $normalizedField === $cookieScript)
                ) {
                    return true;
                }
            }

            return false;
        }

        foreach ($fields as $field) {
            $normalizedField = self::normalizeScriptPath($field, $projectRoot);
            if ($field !== '' && basename($normalizedField !== '' ? $normalizedField : $field) === 'server.php') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>|null
     */
    private static function readCmdlineFields(int $pid): ?array
    {
        $path = "/proc/{$pid}/cmdline";
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $raw = rtrim($raw, "\0");
        return explode("\0", $raw);
    }

    private static function normalizeScriptPath(string $scriptPath, string $projectRoot): string
    {
        $scriptPath = trim($scriptPath);
        if ($scriptPath === '') {
            return '';
        }

        $resolved = @realpath($scriptPath);
        if (is_string($resolved)) {
            return $resolved;
        }

        if (!str_starts_with($scriptPath, '/')) {
            $candidate = $projectRoot . '/' . ltrim($scriptPath, './');
            $resolved = @realpath($candidate);
            if (is_string($resolved)) {
                return $resolved;
            }

            return $candidate;
        }

        return $scriptPath;
    }
}
