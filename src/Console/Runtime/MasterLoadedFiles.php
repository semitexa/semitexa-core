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

    /** Fast and good enough to tell an edited file from the one the master loaded. */
    private const HASH = 'xxh128';

    /**
     * Called by the master just before it forks: what it has loaded is what
     * every worker, reloaded or not, will run. Each file is kept with a hash of
     * its contents, not a time: a change in the same second as the start, or a
     * file put back with an older time, is a change all the same (review, core#231).
     *
     * A file touched since the boot began may have been edited after the master
     * loaded it, so its contents on disk are not proof of what the master holds:
     * it is recorded with no hash, which every reload reads as changed (review,
     * core#231). Times are whole seconds, so "since" includes the boot's own second.
     *
     * @param list<string> $files get_included_files()
     * @param int $bootStartedAt when the master's boot began, before it loaded anything of the application
     * @return bool false when the record could not be written: a reload then refuses
     */
    public static function record(string $projectRoot, array $files, int $at, ?int $bootStartedAt = null): bool
    {
        $root = rtrim($projectRoot, '/') . '/';
        $kept = [];
        foreach ($files as $file) {
            // Compiled caches under var/ are rebuilt by a reload; the rest is code.
            if (str_starts_with($file, $root . 'var/')) {
                continue;
            }
            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            $touchedDuringBoot = $bootStartedAt !== null && ($mtime === false || $mtime >= $bootStartedAt);
            $hash = $touchedDuringBoot ? false : @hash_file(self::HASH, $file);
            $kept[$file] = is_string($hash) ? $hash : '';
        }
        $path = $root . self::RELATIVE_PATH;
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0o775, true);
        }
        $json = json_encode(['at' => $at, 'files' => $kept], JSON_UNESCAPED_SLASHES);
        if (is_string($json) && @file_put_contents($path, $json) !== false) {
            return true;
        }
        // A record left from an earlier master must not stand in for this one.
        @unlink($path);
        @file_put_contents($path, '');

        return false;
    }

    /**
     * The master-loaded files whose contents changed, or that are gone, since the
     * master started: a reload cannot apply any of them. Null when that cannot be
     * known: no record (a master started before this existed, or the record was
     * removed), one that cannot be read, or one older than the running master (a
     * previous master's, left when this one could not write its own). The caller
     * then refuses rather than reports a success it cannot vouch for (Fable review,
     * core#231: a missing record failing open was the very bug, after an update).
     *
     * @param int|null $masterStartedAt when the running master started (its pidfile's time)
     * @return list<string>|null
     */
    public static function changedSince(string $projectRoot, ?int $masterStartedAt = null): ?array
    {
        $path = rtrim($projectRoot, '/') . '/' . self::RELATIVE_PATH;
        if (!file_exists($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || !is_array($record['files'] ?? null) || !is_int($record['at'] ?? null)) {
            return null;
        }
        // Written just before start(); a minute's slack for a slow fork, not for another boot.
        if ($masterStartedAt !== null && $record['at'] < $masterStartedAt - 60) {
            return null;
        }

        $changed = [];
        foreach ($record['files'] as $file => $hash) {
            if (!is_string($file) || !is_string($hash)) {
                return null;
            }
            $now = @hash_file(self::HASH, $file);
            if (!is_string($now) || $hash === '' || $now !== $hash) {
                $changed[] = $file;
            }
        }

        return $changed;
    }
}
