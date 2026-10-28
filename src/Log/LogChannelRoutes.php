<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * LOG_CHANNELS: per-channel destinations, `channel=route` pairs separated by
 * commas. A route is `file` (LOG_FILE), `stderr`, `both`, `off`, or a file of
 * the channel's own under var/log/ ending in `.log` (rotated like LOG_FILE).
 * A channel not named goes where LOG_SINK says.
 *
 *     LOG_CHANNELS=audit=var/log/audit.log,security=both,sql=off
 *
 * A channel was a field on the line and nothing more: every channel went to
 * the one sink. There is no syslog route: syslog() is a blocking send the
 * Swoole hooks cannot reach, and a stalled daemon froze the worker.
 */
final class LogChannelRoutes
{
    /** Framework-owned logs a channel must not write into; see reserved() for the server's own. */
    private const RESERVED = ['var/log/swoole.log', 'var/log/error.log'];

    /**
     * @param list<string> $rejected filled with the pairs that were not understood
     * @return array<string, LogRoute> channel => route
     */
    public static function fromEnv(?string $value, string $logFile, array &$rejected = []): array
    {
        $routes = [];
        foreach (explode(',', (string) $value) as $pair) {
            if (trim($pair) === '') {
                continue;
            }
            [$channel, $route] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            $parsed = $channel !== '' && $route !== '' ? self::parse($route, $logFile) : null;
            if ($parsed === null) {
                $rejected[] = trim($pair);
                continue;
            }
            $routes[$channel] = $parsed;
        }

        return $routes;
    }

    /** The project-relative path in one spelling: `./var//log/a.log` is `var/log/a.log`. */
    public static function normalize(string $path): string
    {
        $path = (string) preg_replace('#/+#', '/', ltrim($path, '/'));
        // Every `./` segment, not only a leading one: `var/log/./swoole.log`
        // passed as another file than swoole.log, and its rotation renamed
        // the server's own log from under it.
        do {
            $path = (string) preg_replace('#(^|/)\./#', '$1', $path, -1, $count);
        } while ($count > 0);

        return $path;
    }

    private static function parse(string $route, string $logFile): ?LogRoute
    {
        return match (strtolower($route)) {
            'off' => new LogRoute(null, false),
            'file' => new LogRoute($logFile, false),
            'stderr' => new LogRoute(null, true),
            'both' => new LogRoute($logFile, true),
            default => self::ownFile($route, $logFile),
        };
    }

    private static function ownFile(string $route, string $logFile): ?LogRoute
    {
        $path = self::normalize($route);
        if (preg_match('#^var/log/(?!.*\.\.)[A-Za-z0-9._/-]+\.log$#', $path) !== 1
            || in_array($path, self::reserved(), true)
            || $path === self::normalize($logFile)
            || self::isTheSwooleLog($path)) {
            return null;
        }

        return new LogRoute($path, false);
    }

    /**
     * reserved() compares spellings; this compares files. A link made
     * SWOOLE_LOG_FILE=/elsewhere/server.log the project's var/log/server.log.
     */
    private static function isTheSwooleLog(string $path): bool
    {
        $swoole = (string) Environment::getEnvValue('SWOOLE_LOG_FILE', '');
        if ($swoole === '') {
            return false;
        }
        $root = rtrim(ProjectRoot::get(), '/') . '/';

        return self::resolved(str_starts_with($swoole, '/') ? $swoole : $root . $swoole) === self::resolved($root . $path);
    }

    /** The path with its longest existing part resolved through links; the file need not exist yet. */
    private static function resolved(string $path): string
    {
        $rest = '';
        while (($real = realpath($path)) === false && $path !== dirname($path)) {
            $rest = '/' . basename($path) . $rest;
            $path = dirname($path);
        }

        return ($real === false ? $path : $real) . $rest;
    }

    /**
     * The fixed list and the file Swoole actually writes: with
     * SWOOLE_LOG_FILE=var/log/server.log a channel could route into it, and
     * its rotation renamed the server's log from under it. An absolute path
     * inside the project is compared in the project-relative spelling; one
     * outside it reserves nothing.
     *
     * @return list<string>
     */
    private static function reserved(): array
    {
        $swoole = (string) Environment::getEnvValue('SWOOLE_LOG_FILE', '');
        $root = rtrim(ProjectRoot::get(), '/') . '/';
        if (str_starts_with($swoole, '/')) {
            // Outside the project it is another file than any channel's,
            // which the logger writes under the project root.
            if (!str_starts_with($swoole, $root)) {
                return self::RESERVED;
            }
            $swoole = substr($swoole, strlen($root));
        }

        return [...self::RESERVED, self::normalize($swoole)];
    }
}
