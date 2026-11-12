<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

/**
 * The PHP files the Swoole master loaded before it forked its workers, and
 * which of them changed since (tk-server-reload-keeps-route-access).
 *
 * The master builds the container before start(), and discovery reflects the
 * classes it finds: payloads, handlers, resources. A reloaded worker is forked
 * from that master, so it gets those classes as the master loaded them, and
 * PHP cannot define a class twice. Measured 2026-11-12: a string added to a
 * handler stayed absent after server:reload printed "reloaded successfully",
 * and server:restart app served it at once. On 2026-11-10 a removed
 * access: Public stayed in force the same way. A reload that cannot apply a
 * change says so instead.
 */
final class MasterLoadedFiles
{
    public const RELATIVE_PATH = 'var/run/master-loaded-files.json';

    /**
     * Called by the master just before it forks: what it has loaded is what
     * every worker, reloaded or not, will run.
     *
     * @param list<string> $files get_included_files()
     */
    public static function record(string $projectRoot, array $files, int $at): void
    {
        $root = rtrim($projectRoot, '/') . '/';
        $kept = [];
        foreach ($files as $file) {
            // Compiled caches under var/ are rebuilt by a reload; the rest is code.
            if (str_starts_with($file, $root . 'var/')) {
                continue;
            }
            $kept[] = $file;
        }
        $path = $root . self::RELATIVE_PATH;
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0o775, true);
        }
        @file_put_contents($path, (string) json_encode(['at' => $at, 'files' => $kept], JSON_UNESCAPED_SLASHES));
    }

    /**
     * The master-loaded files changed or removed since the master started: a
     * reload cannot apply any of them. Empty when nothing is recorded (an older
     * server, or none running).
     *
     * @return list<string>
     */
    public static function changedSince(string $projectRoot): array
    {
        $path = rtrim($projectRoot, '/') . '/' . self::RELATIVE_PATH;
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || !is_int($record['at'] ?? null) || !is_array($record['files'] ?? null)) {
            return [];
        }

        $changed = [];
        foreach ($record['files'] as $file) {
            if (!is_string($file)) {
                continue;
            }
            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime > $record['at']) {
                $changed[] = $file;
            }
        }

        return $changed;
    }
}
