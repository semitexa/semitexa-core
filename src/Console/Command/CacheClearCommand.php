<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Clear application cache (Twig compiled templates, etc.).
 * Use after changing templates or when you see stale layout/template behaviour.
 * When cache was created by Swoole (e.g. in Docker as root), run with --via-docker so the command runs inside the container and can delete the files.
 */
class CacheClearCommand extends BaseCommand
{
    private const CACHE_DIRS = ['twig'];

    protected function configure(): void
    {
        $this->setName('cache:clear')
            ->setDescription('Clear application cache (e.g. var/cache/twig). Use after template changes or when cache is stale.')
            ->addOption('twig', null, InputOption::VALUE_NONE, 'Clear only Twig cache (default: clear all known cache dirs)')
            ->addOption('via-docker', null, InputOption::VALUE_NONE, 'Run the clear inside the app container (use when cache was created by Swoole/Docker and host user cannot delete)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = $this->getProjectRoot();
        $viaDocker = (bool) $input->getOption('via-docker');

        if ($viaDocker) {
            return $this->runViaDocker($io, $root, $input);
        }

        $baseDir = $root . '/var/cache';
        $twigOnly = (bool) $input->getOption('twig');

        $dirsToClear = $twigOnly ? ['twig'] : self::CACHE_DIRS;
        $cleared = [];
        $failed = [];

        foreach ($dirsToClear as $subDir) {
            $path = $baseDir . '/' . $subDir;
            if (!is_dir($path)) {
                continue;
            }
            if ($this->removeDirectoryContents($path)) {
                $cleared[] = 'var/cache/' . $subDir;
            } else {
                $failed[] = 'var/cache/' . $subDir;
            }
        }

        if ($failed !== [] && $this->canRunViaDocker($root)) {
            $io->note('Could not clear from host (permission denied). Running inside Docker container...');
            return $this->runViaDocker($io, $root, $input);
        }

        if ($cleared !== []) {
            $io->success('Cleared: ' . implode(', ', $cleared));
        }
        if ($failed !== []) {
            $io->warning('Could not clear: ' . implode('; ', $failed) . '. Run with --via-docker to clear from inside the app container, or: docker compose exec app bin/semitexa cache:clear');
            return Command::FAILURE;
        }
        if (count($cleared) === 0 && count($failed) === 0) {
            $io->text('Nothing to clear (no cache directories found under var/cache).');
        }

        return Command::SUCCESS;
    }

    private function runViaDocker(SymfonyStyle $io, string $root, InputInterface $input): int
    {
        $args = ['bin/semitexa', 'cache:clear'];
        if ($input->getOption('twig')) {
            $args[] = '--twig';
        }
        $process = new Process(['docker', 'compose', 'exec', '-T', 'app', 'php', ...$args], $root);
        $process->setTimeout(30);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });
        if ($process->isSuccessful()) {
            $io->success('Cache cleared (via Docker).');
            return Command::SUCCESS;
        }
        $io->error('Docker exec failed. Is the app container running? Try: docker compose exec app bin/semitexa cache:clear');
        return Command::FAILURE;
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


    /**
     * Remove all contents of a directory (files and subdirs). Directory itself is kept.
     */
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
}
