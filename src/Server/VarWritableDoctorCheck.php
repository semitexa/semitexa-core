<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Whether the directories the server logs into are writable by, and owned by,
 * the user running this check (tk-root-owned-var-hangs-the-server). A command
 * run as root leaves var/ owned by root; the server, another uid, then hangs at
 * its next start on a log file it cannot create. The server refuses that start
 * itself; this says so before the restart.
 *
 * Run it as the server's user: it judges the uid it runs under.
 */
#[AsDoctorCheck(name: 'var.writable', package: 'semitexa/core')]
final class VarWritableDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        $root = ProjectRoot::get();
        $paths = [];
        foreach (WritableDirectory::currentStartPaths() as $path => $open) {
            // Never created from here: made by a doctor run as root, a missing var/log would be
            // root's, and the next start as the server's user could not write it.
            $paths[] = ['path' => $path, 'chownTarget' => WritableDirectory::chownTarget($path, $root), 'open' => $open]
                + WritableDirectory::facts($path, false, $open);
        }

        return self::verdict($paths, WritableDirectory::processUid(), WritableDirectory::processGid());
    }

    /**
     * For a path that does not exist, `writable`, the owner and the mode are
     * those of `described`, its nearest existing parent. `open` is how an
     * existing file is opened, as {@see WritableDirectory::startPaths()} gives it.
     *
     * @param list<array{path: string, chownTarget: string, open?: ?string, exists: bool, writable: bool, ownerUid: ?int, ownerGid: ?int, mode: ?int, described: string, blocked: bool}> $paths
     */
    public static function verdict(array $paths, int $processUid, int $processGid): DoctorResult
    {
        $verdict = null;
        $missing = [];
        foreach ($paths as $p) {
            $owner = ($p['ownerUid'] ?? '?') . ':' . ($p['ownerGid'] ?? '?')
                . ', mode ' . ($p['mode'] === null ? '?' : sprintf('%04o', $p['mode']));
            $repair = WritableDirectory::repair($p['ownerUid'], $p['ownerGid'], $p['mode'], $processUid, $processGid, $p['chownTarget'], $p['described'], $p['blocked'], ($p['open'] ?? null) === 'a+');
            $foreign = $p['ownerUid'] !== null && $p['ownerUid'] !== $processUid;
            if ($processUid === 0) {
                // The write was tried, not read from the mode: what stops root (a read-only mount,
                // a file where the directory has to be) stops the server's user too.
                if (!$p['writable']) {
                    return DoctorResult::fail(
                        ($p['exists']
                            ? "{$p['path']} is not writable even by root (owner {$owner})"
                            : "{$p['path']} does not exist and even root cannot create it in {$p['described']} (owner {$owner})")
                        . ': the server refuses to start, because it would hang on its log file.',
                        $p['blocked']
                            ? $repair
                            : "No owner or mode stops root: look for a read-only mount or an immutable flag on {$p['described']}.",
                    );
                }
                // Root writes anywhere else and owns nothing here by right: a chown to 0 is the incident itself.
                if (!$p['exists'] || $foreign) {
                    $verdict ??= DoctorResult::warn(
                        'This check ran as root, and ' . $p['path']
                        . ($p['exists'] ? " is owned by {$owner}" : ' does not exist (the server creates it at its start, as its own user)')
                        . ": it cannot judge the server's user.",
                        'Run system:doctor as the user the server runs as.',
                    );
                }
                continue;
            }
            if (!$p['writable']) {
                return DoctorResult::fail(
                    ($p['exists']
                        ? "{$p['path']} is not writable by uid {$processUid} (owner {$owner})"
                        : "{$p['path']} does not exist and uid {$processUid} cannot create it in {$p['described']} (owner {$owner})")
                    . ': the server refuses to start, because it would hang on its log file.',
                    $repair,
                );
            }
            if (!$p['exists']) {
                $missing[] = $p['path'];
            } elseif ($foreign) {
                $verdict ??= DoctorResult::fail(
                    "{$p['path']} is owned by {$owner}, not by uid {$processUid}: writable today, but files another user "
                    . 'creates in it (a command run as root) are not, and the server hangs on a log file it cannot open.',
                    $repair,
                );
            }
        }

        return $verdict ?? DoctorResult::pass(
            'The log and run paths are writable and owned by uid ' . $processUid . '.'
            . ($missing === [] ? '' : ' Not there yet, created at the server\'s start: ' . implode(', ', $missing) . '.'),
        );
    }
}
