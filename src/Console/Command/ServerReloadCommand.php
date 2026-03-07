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

        $this->rebuildAutoload($io);

        $pid = $this->findMasterPid();

        if ($pid === null) {
            $io->error('Could not find Swoole master process. Is the server running?');
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
            if (file_exists($path)) {
                $pid = (int) trim(file_get_contents($path));
                if ($pid > 0 && posix_kill($pid, 0)) {
                    return $pid;
                }
            }
        }

        // Fallback: scan /proc for the Swoole master process (PID 1 in containers,
        // or the parent of worker processes outside containers)
        if (is_dir('/proc')) {
            foreach (glob('/proc/[0-9]*/cmdline') as $cmdlineFile) {
                $cmdline = @file_get_contents($cmdlineFile);
                if ($cmdline !== false && str_contains($cmdline, 'server.php')) {
                    $pid = (int) basename(dirname($cmdlineFile));
                    // The master is the one whose parent is NOT another server.php process,
                    // or simply the lowest-PID match
                    if ($pid > 0 && posix_kill($pid, 0)) {
                        return $pid;
                    }
                }
            }
        }

        return null;
    }
}
