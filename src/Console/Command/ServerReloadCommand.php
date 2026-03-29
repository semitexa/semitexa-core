<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'server:reload', description: 'Gracefully reload all Swoole workers (pick up code changes without restarting containers)')]
class ServerReloadCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('server:reload')
            ->setDescription('Gracefully reload all Swoole workers (pick up code changes without restarting containers)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->rebuildAutoload($io)) {
            return Command::FAILURE;
        }

        $pid = $this->findMasterPid();

        if ($pid === null) {
            $io->error('Could not find Swoole master process. Is the server running?');
            return Command::FAILURE;
        }

        if (!function_exists('posix_kill')) {
            $io->error('posix_kill() is unavailable; server reload requires the POSIX extension.');
            return Command::FAILURE;
        }

        posix_kill($pid, SIGUSR1);

        $io->success("Reload signal (SIGUSR1) sent to Swoole master process (PID {$pid}). All workers will gracefully restart.");

        return Command::SUCCESS;
    }

    private function findMasterPid(): ?int
    {
        // Try known PID file locations
        $root = $this->getProjectRoot();
        $candidates = [
            $root . '/var/run/semitexa.pid',
            $root . '/var/swoole.pid',
        ];

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                $pidRaw = file_get_contents($path);
                if ($pidRaw === false) {
                    continue;
                }

                $pid = (int) trim($pidRaw);
                // posix_kill($pid, 0) only checks existence — the OS may have recycled the PID
                // for a different process after a crash. Verify it's actually a server.php process.
                if ($pid > 0 && posix_kill($pid, 0) && $this->isSwooleProcess($pid)) {
                    return $pid;
                }
            }
        }

        // Fallback: scan /proc for the Swoole master process (PID 1 in containers,
        // or the parent of worker processes outside containers).
        // Collect ALL matching PIDs and return the minimum: the master always has the lowest
        // PID because workers are forked from it (and glob order is not guaranteed).
        if (is_dir('/proc')) {
            $matchedPids = [];
            foreach (glob('/proc/[0-9]*/cmdline') as $cmdlineFile) {
                $cmdline = @file_get_contents($cmdlineFile);
                if ($cmdline !== false && str_contains($cmdline, 'server.php')) {
                    $pid = (int) basename(dirname($cmdlineFile));
                    if ($pid > 0 && posix_kill($pid, 0)) {
                        $matchedPids[] = $pid;
                    }
                }
            }
            if ($matchedPids !== []) {
                sort($matchedPids);
                return $matchedPids[0];
            }
        }

        return null;
    }

    private function isSwooleProcess(int $pid): bool
    {
        $cmdlineFile = "/proc/{$pid}/cmdline";
        if (!is_readable($cmdlineFile)) {
            // Not on Linux (e.g. macOS) — skip cmdline check, trust the PID file
            return true;
        }
        $cmdline = @file_get_contents($cmdlineFile);
        return $cmdline !== false && str_contains($cmdline, 'server.php');
    }
}
