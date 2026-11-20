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
        $directories = [];
        foreach (WritableDirectory::currentStartDirectories() as $directory) {
            $facts = WritableDirectory::facts($directory);
            $directories[] = [
                'path' => $directory,
                'chownTarget' => WritableDirectory::chownTarget($directory, $root),
                'exists' => $facts['exists'],
                'writable' => $facts['writable'],
                'ownerUid' => $facts['ownerUid'],
                'ownerGid' => $facts['ownerGid'],
                'mode' => $facts['mode'],
            ];
        }

        return self::verdict($directories, WritableDirectory::processUid(), WritableDirectory::processGid());
    }

    /**
     * @param list<array{path: string, chownTarget: string, exists: bool, writable: bool, ownerUid: ?int, ownerGid: ?int, mode: ?int}> $directories
     */
    public static function verdict(array $directories, int $processUid, int $processGid): DoctorResult
    {
        $foreign = [];
        foreach ($directories as $d) {
            $owner = ($d['ownerUid'] ?? '?') . ':' . ($d['ownerGid'] ?? '?')
                . ', mode ' . ($d['mode'] === null ? '?' : sprintf('%04o', $d['mode']));
            if (!$d['exists'] || !$d['writable']) {
                return DoctorResult::fail(
                    $d['path'] . ($d['exists'] ? ' is not writable' : ' does not exist and cannot be created')
                    . " by uid {$processUid} (owner {$owner}): the server refuses to start, because it would hang on its log file.",
                    WritableDirectory::fix($processUid, $processGid, $d['chownTarget']),
                );
            }
            if ($d['ownerUid'] !== null && $d['ownerUid'] !== $processUid) {
                $foreign[] = [$d, $owner];
            }
        }
        if ($foreign === []) {
            return DoctorResult::pass('The log and run directories are writable and owned by uid ' . $processUid . '.');
        }
        [$d, $owner] = $foreign[0];
        if ($processUid === 0) {
            // Root writes anywhere and owns nothing here by right: a chown to 0 is the incident itself.
            return DoctorResult::warn(
                "This check ran as root, and {$d['path']} is owned by {$owner}: it cannot judge the server's user.",
                'Run system:doctor as the user the server runs as.',
            );
        }

        return DoctorResult::fail(
            "{$d['path']} is owned by {$owner}, not by uid {$processUid}: writable today, but files another user "
            . 'creates in it (a command run as root) are not, and the server hangs on a log file it cannot open.',
            WritableDirectory::fix($processUid, $processGid, $d['chownTarget']),
        );
    }
}
