<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Whether the server's own process can write where it logs
 * (tk-root-owned-var-hangs-the-server).
 *
 * On 20.11 a `bin/semitexa update` run as root left var/ and var/log owned by
 * root. The server, uid 1000, could not create that day's swoole.log.<date>:
 * every worker blocked for good inside Swoole's logger, the accept queue filled,
 * and nothing was written anywhere, because the log was the thing that failed.
 * So the master asks first, and refuses with the fix instead of hanging.
 */
final class WritableDirectory
{
    /**
     * The decision, from the facts alone. Null when the process can write the
     * directory; otherwise one block naming the path, its owner and mode, the
     * process uid and the fix. A null owner or mode is one that could not be read.
     */
    public static function problem(
        string $path,
        bool $exists,
        bool $writable,
        ?int $ownerUid,
        ?int $ownerGid,
        ?int $mode,
        int $processUid,
        int $processGid,
        string $chownTarget,
    ): ?string {
        if ($exists && $writable) {
            return null;
        }

        return implode("\n", [
            $exists
                ? "[Semitexa] {$path} is not writable by this process (uid {$processUid})."
                : "[Semitexa] {$path} does not exist and this process (uid {$processUid}) cannot create it.",
            '[Semitexa]   ' . self::ownerLine($exists ? 'owner' : 'nearest existing parent: owner', $ownerUid, $ownerGid, $mode),
            '[Semitexa]   fix: ' . self::fix($processUid, $processGid, $chownTarget),
        ]);
    }

    /** The same fix the start refusal and the doctor's hint give. */
    public static function fix(int $processUid, int $processGid, string $chownTarget): string
    {
        return 'chown -R ' . $processUid . ':' . $processGid . ' ' . escapeshellarg($chownTarget)
            . ' (as root), or start with bin/semitexa server:start, which reclaims ownership.';
    }

    /**
     * The facts about one directory, gathered by writing: a probe file is
     * created and removed, because is_writable() answers from the mode bits and
     * is wrong on a read-only mount or under an ACL. A missing directory is
     * created first, as Swoole would not.
     *
     * @return array{exists: bool, writable: bool, ownerUid: ?int, ownerGid: ?int, mode: ?int}
     */
    public static function facts(string $directory): array
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
            clearstatcache(true, $directory);
        }
        $exists = is_dir($directory);
        $writable = false;
        if ($exists) {
            $probe = $directory . '/.semitexa-write-probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
            $writable = @file_put_contents($probe, '') !== false;
            if ($writable) {
                @unlink($probe);
            }
        }
        // A missing directory has no owner: report whoever owns the place it could not be made in.
        $described = $directory;
        while (!$exists && !file_exists($described) && dirname($described) !== $described) {
            $described = dirname($described);
        }
        $owner = @fileowner($described);
        $group = @filegroup($described);
        $perms = @fileperms($described);

        return [
            'exists' => $exists,
            'writable' => $writable,
            'ownerUid' => $owner === false ? null : $owner,
            'ownerGid' => $group === false ? null : $group,
            'mode' => $perms === false ? null : $perms & 07777,
        ];
    }

    /** Null when the process can write $directory, else the block of {@see problem()}. */
    public static function inspect(string $directory, ?string $projectRoot = null): ?string
    {
        $facts = self::facts($directory);

        return self::problem(
            $directory,
            $facts['exists'],
            $facts['writable'],
            $facts['ownerUid'],
            $facts['ownerGid'],
            $facts['mode'],
            self::processUid(),
            self::processGid(),
            self::chownTarget($directory, $projectRoot ?? ProjectRoot::get()),
        );
    }

    /**
     * What to chown: the project's var/ when the directory is inside it (a root
     * run leaves more than one directory behind), else the directory itself.
     */
    public static function chownTarget(string $directory, string $projectRoot): string
    {
        $var = rtrim($projectRoot, '/') . '/var';

        return $directory === $var || str_starts_with($directory, $var . '/') ? $var : $directory;
    }

    /**
     * The directories the server writes at start, before any request: the one
     * Swoole creates its (rotated) log file in, resolved as ServerConfigurator
     * hands it to Swoole; var/log, where ErrorHandler puts error.log; and
     * var/run, where the pid file goes.
     *
     * @return list<string>
     */
    public static function startDirectories(ServerConfigurator $config, ?string $projectRoot = null): array
    {
        $root = rtrim($projectRoot ?? ProjectRoot::get(), '/');

        return array_values(array_unique([
            dirname($config->logFilePath()),
            $root . '/var/log',
            $root . '/var/run',
        ]));
    }

    /**
     * {@see startDirectories()} for the environment as it is now.
     *
     * @return list<string>
     */
    public static function currentStartDirectories(): array
    {
        return self::startDirectories(new ServerConfigurator(Environment::create()));
    }

    /**
     * The start refusal: 0 when every directory can be written, else 1 after
     * one block on $stderr. Refusing is the point: a server that cannot open
     * its log does not fail, it hangs with every worker blocked and no word.
     *
     * @param list<string> $directories
     * @param resource $stderr
     */
    public static function refusalExitCode(array $directories, $stderr, ?string $projectRoot = null): int
    {
        $problems = [];
        foreach ($directories as $directory) {
            $problem = self::inspect($directory, $projectRoot);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        if ($problems === []) {
            return 0;
        }
        fwrite($stderr, implode("\n", [
            '[Semitexa] ============================================================',
            '[Semitexa] THE SERVER WILL NOT START: it cannot write its log directory.',
            '[Semitexa] Started anyway, every worker would block on the log file',
            '[Semitexa] and the server would hang without a single log line.',
            ...$problems,
            '[Semitexa] ============================================================',
        ]) . "\n");

        return 1;
    }

    public static function processUid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : (int) @fileowner('/proc/self');
    }

    public static function processGid(): int
    {
        return function_exists('posix_getegid') ? posix_getegid() : (int) @filegroup('/proc/self');
    }

    private static function ownerLine(string $label, ?int $ownerUid, ?int $ownerGid, ?int $mode): string
    {
        return sprintf(
            '%s %s:%s, mode %s',
            $label,
            $ownerUid ?? '?',
            $ownerGid ?? '?',
            $mode === null ? '?' : sprintf('%04o', $mode),
        );
    }
}
