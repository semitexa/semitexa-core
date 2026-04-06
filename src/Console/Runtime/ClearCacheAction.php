<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final class ClearCacheAction
{
    private const CACHE_DIRS = ['twig'];

    public function __construct(private readonly SymfonyStyle $io) {}

    public function execute(): bool
    {
        $this->io->text('<info>[prepare]</info> Clearing application cache...');

        $root = ProjectRoot::get();
        $baseDir = $root . '/var/cache';
        $cleared = [];
        $failed = [];

        foreach (self::CACHE_DIRS as $subDir) {
            $path = $baseDir . '/' . $subDir;
            if (!is_dir($path)) {
                continue;
            }
            if ($this->removeDirectoryContents($path)) {
                $cleared[] = 'var/cache/' . $subDir;
            } else {
                $failed[] = $subDir;
            }
        }

        // If host permissions failed, try via Docker
        if ($failed !== [] && $this->canRunViaDocker($root)) {
            $this->io->text('<info>[prepare]</info> Cache owned by Docker, clearing inside container...');
            $process = new Process(
                ['docker', 'compose', 'exec', '-T', 'app', 'php', 'bin/semitexa', 'cache:clear'],
                $root,
            );
            $process->setTimeout(30);
            $process->run();
            if ($process->isSuccessful()) {
                $this->io->text('<info>[prepare]</info> Cache cleared (via Docker).');
                return true;
            }
            $this->io->warning('Failed to clear cache via Docker. Stale templates may remain.');
            return true; // non-fatal — don't block start/restart
        }

        if ($cleared !== []) {
            $this->io->text('<info>[prepare]</info> Cache cleared: ' . implode(', ', $cleared));
        } elseif ($failed === []) {
            $this->io->text('<info>[prepare]</info> No cache to clear.');
        }

        if ($failed !== []) {
            $this->io->warning('Could not clear cache dirs: ' . implode(', ', $failed) . '. Run: docker compose exec app bin/semitexa cache:clear');
        }

        return true; // cache clear is best-effort, never blocks lifecycle
    }

    private function removeDirectoryContents(string $path): bool
    {
        if (!is_dir($path) || !is_readable($path)) {
            return false;
        }
        $ok = true;
        $items = @scandir($path);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $ok = $this->removeDirectoryRecursive($full) && $ok;
            } else {
                $ok = @unlink($full) && $ok;
            }
        }
        return $ok;
    }

    private function removeDirectoryRecursive(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }
        $items = @scandir($path);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDirectoryRecursive($full);
            }
            @unlink($full);
        }
        return @rmdir($path);
    }

    private function canRunViaDocker(string $root): bool
    {
        if (!is_file($root . '/docker-compose.yml')) {
            return false;
        }
        $process = new Process(['docker', 'compose', 'exec', '-T', 'app', 'true'], $root);
        $process->setTimeout(5);
        $process->run();
        return $process->isSuccessful();
    }
}
