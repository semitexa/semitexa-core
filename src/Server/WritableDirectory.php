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
        ?string $chmodTarget = null,
        bool $blocked = false,
        bool $readToo = false,
    ): ?string {
        if ($exists && $writable) {
            return null;
        }

        return implode("\n", [
            $exists
                ? "[Semitexa] {$path} is not writable by this process (uid {$processUid})."
                : "[Semitexa] {$path} does not exist and this process (uid {$processUid}) cannot create it.",
            '[Semitexa]   ' . self::ownerLine($exists ? 'owner' : 'nearest existing parent: owner', $ownerUid, $ownerGid, $mode),
            '[Semitexa]   fix: ' . self::repair($ownerUid, $ownerGid, $mode, $processUid, $processGid, $chownTarget, $chmodTarget ?? $path, $blocked, $readToo),
        ]);
    }

    /**
     * The repair that fits the facts, the same in the start refusal and the
     * doctor's hint. Another owner: chown. The process's own path without the
     * write bit: chmod, because a chown to the owner it already has changes
     * nothing. Another owner whose group is the process's: the group is what
     * lets it write, so chmod ug+w is named beside the chown. $blocked: a file
     * stands where the directory has to be, at the path or above it, and no
     * owner or mode repairs that. $readToo: the path is the Swoole log, opened
     * for reading as well, so a mode without the owner's read bit is the fault
     * even when the write bit is there.
     */
    public static function repair(
        ?int $ownerUid,
        ?int $ownerGid,
        ?int $mode,
        int $processUid,
        int $processGid,
        string $chownTarget,
        string $chmodTarget,
        bool $blocked = false,
        bool $readToo = false,
    ): string {
        if ($blocked) {
            return 'move ' . escapeshellarg($chmodTarget) . ' away: it is a file where a directory has to be.';
        }
        $chmod = static fn (string $bits): string => 'chmod ' . $bits . ' ' . escapeshellarg($chmodTarget);
        $noRead = $readToo && $mode !== null && ($mode & 0600) === 0200;
        if ($ownerUid === $processUid) {
            if ($noRead) {
                return $chmod('u+r') . ': uid ' . $processUid . ' owns it, and the mode forbids the read Swoole opens its log with.';
            }

            return $mode !== null && ($mode & 0200) === 0
                ? $chmod('u+w') . ': uid ' . $processUid . ' owns it, the mode forbids the write.'
                : 'the owner and the mode allow the write, so look for a read-only mount or an ACL on ' . $chmodTarget . '.';
        }
        $fix = self::fix($processUid, $processGid, $chownTarget);
        if ($mode !== null && $ownerGid === $processGid && ($mode & 0220) !== 0220) {
            return $fix . ' Or, to write through group ' . $processGid . ': ' . $chmod('ug+w') . '.';
        }
        if ($mode !== null && ($mode & 0200) === 0) {
            return $fix . ' Then ' . $chmod('u+w') . ': the mode forbids the owner the write.';
        }
        if ($noRead) {
            return $fix . ' Then ' . $chmod('u+r') . ': the mode forbids the owner the read Swoole opens its log with.';
        }

        return $fix;
    }

    /** The repair for another owner. */
    public static function fix(int $processUid, int $processGid, string $chownTarget): string
    {
        return 'chown -R ' . $processUid . ':' . $processGid . ' ' . escapeshellarg($chownTarget)
            . ' (as root), or start with bin/semitexa server:start, which reclaims ownership.';
    }

    /**
     * The facts about one path, gathered by writing, because is_writable()
     * answers from the mode bits and is wrong on a read-only mount or under an
     * ACL. A directory gets a probe file, created and removed.
     *
     * The path is a directory unless $open says it is a file the server appends
     * to (see {@see startPaths()}), and names how its writer opens it: 'a+',
     * read and write, for the Swoole log, because Swoole opens its log
     * O_RDWR|O_APPEND|O_CREAT and a write-only one (mode 0200) refuses that;
     * 'a' for error.log, which PHP only appends to. Only then is it opened,
     * that way, and closed, nothing written and nothing truncated; and only a
     * regular file is: opening a FIFO nobody reads blocks for good, the hang
     * this exists to prevent. Anything but a directory where a directory is required is
     * `blocked`, however writable: Swoole cannot create its log inside a file.
     *
     * A missing directory is created only when $create is set, and only the
     * server's start sets it: made by a diagnostic run as root it would be
     * root's, the very fault this looks for. Left missing, `writable` says
     * whether the nearest existing parent could take it, and the owner and mode
     * are that parent's, at `described`.
     *
     * @param 'a'|'a+'|null $open
     * @return array{exists: bool, writable: bool, ownerUid: ?int, ownerGid: ?int, mode: ?int, described: string, blocked: bool}
     */
    public static function facts(string $path, bool $create = false, ?string $open = null): array
    {
        if ($create && $open === null && !file_exists($path)) {
            @mkdir($path, 0775, true);
        }
        clearstatcache(true, $path);
        $exists = file_exists($path);
        $described = $path;
        while (!file_exists($described) && dirname($described) !== $described) {
            $described = dirname($described);
        }
        $writable = false;
        $blocked = false;
        if ($open !== null && $exists) {
            $handle = is_file($path) ? @fopen($path, $open) : false;
            if ($handle !== false) {
                $writable = true;
                fclose($handle);
            }
        } elseif (is_dir($described)) {
            $probe = $described . '/.semitexa-write-probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
            $writable = @file_put_contents($probe, '') !== false;
            if ($writable) {
                @unlink($probe);
            }
        } else {
            $blocked = true; // a file stands where a directory has to be
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
            'described' => $described,
            'blocked' => $blocked,
        ];
    }

    /**
     * Null when the process can write $directory, else the block of {@see problem()}.
     * With $create, a missing directory is made first: the server's start only.
     * With $open, the path is an existing file opened that way, as in {@see facts()}.
     *
     * @param 'a'|'a+'|null $open
     */
    public static function inspect(string $directory, ?string $projectRoot = null, bool $create = false, ?string $open = null): ?string
    {
        $facts = self::facts($directory, $create, $open);

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
            $facts['described'],
            $facts['blocked'],
            $open === 'a+',
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
     * What the server writes at start, before any request, as ServerConfigurator
     * hands it to Swoole:
     *
     * - the Swoole log. With rotation on Swoole creates `<log_file>.<date>`, so
     *   the directory has to take a new file. With SWOOLE_LOG_ROTATION=single
     *   and the file there, Swoole only opens that file and never makes a
     *   sibling (swoole-src v6.2 src/core/log.cc): the file is the path, and a
     *   read-only directory around it is a valid setup;
     * - error.log in var/log, by the same rule: PHP appends to an existing one;
     * - var/run, where the pid file is created.
     *
     * Each path carries its kind, because a writable file where a directory
     * has to be can take no log file.
     *
     * @return array<string, 'a'|'a+'|null> path => how an existing file is opened ('a+' the Swoole log, read
     *                                      and write as Swoole opens it; 'a' error.log), null for a directory
     */
    public static function startPaths(ServerConfigurator $config, ?string $projectRoot = null): array
    {
        $root = rtrim($projectRoot ?? ProjectRoot::get(), '/');
        $logFile = $config->logFilePath();

        $errorLog = $root . '/var/log/error.log';
        $logIsFile = !$config->logRotation()->isEnabled() && is_file($logFile);
        $errorLogIsFile = is_file($errorLog);

        $paths = [];
        $paths[$logIsFile ? $logFile : dirname($logFile)] = $logIsFile ? 'a+' : null;
        // ??=: one file that is both logs keeps the stricter open, Swoole's.
        $paths[$errorLogIsFile ? $errorLog : dirname($errorLog)] ??= $errorLogIsFile ? 'a' : null;
        $paths[$root . '/var/run'] = null;

        return $paths;
    }

    /**
     * {@see startPaths()} for the environment as it is now.
     *
     * @return array<string, 'a'|'a+'|null>
     */
    public static function currentStartPaths(): array
    {
        return self::startPaths(new ServerConfigurator(Environment::create()));
    }

    /**
     * The start refusal: 0 when every path can be written, else 1 after one
     * block on $stderr. A missing directory is created here, by the server's
     * own user, and nowhere else. Refusing is the point: a server that cannot open
     * its log does not fail, it hangs with every worker blocked and no word.
     *
     * @param array<string, 'a'|'a+'|null> $paths as {@see startPaths()} returns them
     * @param resource $stderr
     */
    public static function refusalExitCode(array $paths, $stderr, ?string $projectRoot = null): int
    {
        $problems = [];
        foreach ($paths as $path => $open) {
            $problem = self::inspect($path, $projectRoot, true, $open);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        if ($problems === []) {
            return 0;
        }
        fwrite($stderr, implode("\n", [
            '[Semitexa] ============================================================',
            '[Semitexa] THE SERVER WILL NOT START: it cannot write where it logs.',
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
