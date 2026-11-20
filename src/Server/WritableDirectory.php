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
    ): ?string {
        if ($exists && $writable) {
            return null;
        }

        return implode("\n", [
            $exists
                ? "[Semitexa] {$path} is not writable by this process (uid {$processUid})."
                : "[Semitexa] {$path} does not exist and this process (uid {$processUid}) cannot create it.",
            '[Semitexa]   ' . self::ownerLine($exists ? 'owner' : 'nearest existing parent: owner', $ownerUid, $ownerGid, $mode),
            '[Semitexa]   fix: ' . self::repair($ownerUid, $ownerGid, $mode, $processUid, $processGid, $chownTarget, $chmodTarget ?? $path, $blocked),
        ]);
    }

    /**
     * The repair that fits the facts, the same in the start refusal and the
     * doctor's hint. Another owner: chown. The process's own path without the
     * write bit: chmod, because a chown to the owner it already has changes
     * nothing. Another owner whose group is the process's: the group is what
     * lets it write, so chmod ug+w is named beside the chown. $blocked: a file
     * stands where the directory has to be, and no owner or mode repairs that.
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
    ): string {
        if ($blocked) {
            return 'move ' . escapeshellarg($chmodTarget) . ' away: it is a file where a directory has to be.';
        }
        $chmod = static fn (string $bits): string => 'chmod ' . $bits . ' ' . escapeshellarg($chmodTarget);
        if ($ownerUid === $processUid) {
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
     * ACL. An existing file is opened for append and closed, nothing written
     * and nothing truncated. A directory gets a probe file, created and removed.
     *
     * A missing directory is created only when $create is set, and only the
     * server's start sets it: made by a diagnostic run as root it would be
     * root's, the very fault this looks for. Left missing, `writable` says
     * whether the nearest existing parent could take it, and the owner and mode
     * are that parent's, at `described`.
     *
     * @return array{exists: bool, writable: bool, ownerUid: ?int, ownerGid: ?int, mode: ?int, described: string, blocked: bool}
     */
    public static function facts(string $path, bool $create = false): array
    {
        if ($create && !file_exists($path)) {
            @mkdir($path, 0775, true);
        }
        clearstatcache(true, $path);
        $exists = file_exists($path);
        $described = $path;
        while (!file_exists($described) && dirname($described) !== $described) {
            $described = dirname($described);
        }
        if (is_dir($described)) {
            $probe = $described . '/.semitexa-write-probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
            $writable = @file_put_contents($probe, '') !== false;
            if ($writable) {
                @unlink($probe);
            }
        } elseif ($exists) {
            $handle = @fopen($described, 'a');
            $writable = $handle !== false;
            if ($handle !== false) {
                fclose($handle);
            }
        } else {
            $writable = false; // a file stands where a directory has to be
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
            'blocked' => !$exists && !is_dir($described),
        ];
    }

    /**
     * Null when the process can write $directory, else the block of {@see problem()}.
     * With $create, a missing directory is made first: the server's start only.
     */
    public static function inspect(string $directory, ?string $projectRoot = null, bool $create = false): ?string
    {
        $facts = self::facts($directory, $create);

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
     * @return list<string> existing files to open for append, and directories
     */
    public static function startPaths(ServerConfigurator $config, ?string $projectRoot = null): array
    {
        $root = rtrim($projectRoot ?? ProjectRoot::get(), '/');
        $logFile = $config->logFilePath();

        return array_values(array_unique([
            !$config->logRotation()->isEnabled() && is_file($logFile) ? $logFile : dirname($logFile),
            is_file($root . '/var/log/error.log') ? $root . '/var/log/error.log' : $root . '/var/log',
            $root . '/var/run',
        ]));
    }

    /**
     * {@see startPaths()} for the environment as it is now.
     *
     * @return list<string>
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
     * @param list<string> $directories
     * @param resource $stderr
     */
    public static function refusalExitCode(array $directories, $stderr, ?string $projectRoot = null): int
    {
        $problems = [];
        foreach ($directories as $directory) {
            $problem = self::inspect($directory, $projectRoot, true);
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
