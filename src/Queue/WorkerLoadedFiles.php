<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * The PHP files worker:run had loaded when it began to fork, and which of
 * them changed since. A role is forked from that process, so it runs those
 * files as they were loaded, whatever is on disk now: PHP cannot define a
 * class twice. A role that had a php of its own read the disk at every start;
 * this is what tells the operator when a role started again no longer does.
 * The same question the server asks of its master (MasterLoadedFiles), kept in
 * memory here: the process that asks is the one that loaded them.
 */
final class WorkerLoadedFiles
{
    /** Fast and good enough to tell an edited file from the one that was loaded. */
    private const HASH = 'xxh128';

    /** @var array<string, array{mtime: int, size: int, hash: string}> */
    private array $files = [];

    /**
     * A file touched since the boot began may have been edited after it was
     * loaded, so what is on disk is no proof of what this process holds: it is
     * kept with no hash and always reads as changed (as MasterLoadedFiles does).
     *
     * The hash is of the file as it is when read, which is later than the
     * time and size were: a file replaced in between would be kept with the
     * replacement's hash, and changed() would then call the replacement
     * unchanged while the roles run the original. So the file is looked at
     * again after hashing, and one that is not the same file any more (time,
     * change time, size or inode) is kept with no hash as well.
     *
     * @param list<string>                         $files         get_included_files()
     * @param int                                  $bootStartedAt when this process began, before it loaded anything of the application
     * @param (\Closure(string): (string|false))|null $hash          hashes one file; hash_file() unless a test says otherwise
     */
    public function __construct(array $files, int $bootStartedAt, string $projectRoot = '', ?\Closure $hash = null)
    {
        $hash ??= static fn (string $file): string|false => @hash_file(self::HASH, $file);
        $var = $projectRoot === '' ? null : rtrim($projectRoot, '/') . '/var/';
        clearstatcache();
        foreach ($files as $file) {
            // Compiled caches under var/ are data the framework rebuilds; the rest is code.
            if ($var !== null && str_starts_with($file, $var)) {
                continue;
            }
            $stat = @stat($file);
            if ($stat === false) {
                $this->files[$file] = ['mtime' => 0, 'size' => 0, 'hash' => ''];
                continue;
            }
            $hashed = $stat['mtime'] >= $bootStartedAt ? false : $hash($file);
            if (is_string($hashed)) {
                clearstatcache(true, $file);
                $after = @stat($file);
                $same = $after !== false
                    && $after['mtime'] === $stat['mtime'] && $after['ctime'] === $stat['ctime']
                    && $after['size'] === $stat['size'] && $after['ino'] === $stat['ino']
                    && $after['mtime'] < $bootStartedAt;
                if (!$same) {
                    $hashed = false;
                }
            }
            $this->files[$file] = ['mtime' => $stat['mtime'], 'size' => $stat['size'], 'hash' => is_string($hashed) ? $hashed : ''];
        }
    }

    /**
     * The loaded files whose contents changed, or that are gone. A file with
     * the time and size it had is taken as unchanged without being read, so
     * this can be asked at every start of a role.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        clearstatcache();
        $changed = [];
        foreach ($this->files as $file => $was) {
            $stat = @stat($file);
            if ($stat === false || $was['hash'] === '') {
                $changed[] = $file;
                continue;
            }
            if ($stat['mtime'] === $was['mtime'] && $stat['size'] === $was['size']) {
                continue;
            }
            if (@hash_file(self::HASH, $file) !== $was['hash']) { // the file itself, not the constructor's hasher
                $changed[] = $file;
            }
        }

        return $changed;
    }
}
