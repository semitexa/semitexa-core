<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class ServerStopCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('server:stop')
            ->setDescription('Stop Swoole HTTP server (Docker or local process)')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Port to stop (used when not using Docker). Default: from .env SWOOLE_PORT');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rootDir = $this->getProjectRoot();
        $port = $input->getOption('port');
        if ($port === null || $port === '') {
            $port = $this->getPortFromEnv($rootDir);
        }

        $io->title('Stopping Semitexa...');

        // 1. If started via Docker (server:start), stop containers first
        $composeFile = $rootDir . '/docker-compose.yml';
        if (file_exists($composeFile)) {
            $io->section('Stopping Docker containers (docker compose down)...');
            $composeArgs = ['docker', 'compose'];
            $overlays = [
                'docker-compose.rabbitmq.yml',
                'docker-compose.mysql.yml',
                'docker-compose.redis.yml',
            ];
            $activeOverlays = array_filter($overlays, fn (string $f) => file_exists($rootDir . '/' . $f));
            if ($activeOverlays !== []) {
                $composeArgs[] = '-f';
                $composeArgs[] = 'docker-compose.yml';
                foreach ($activeOverlays as $overlay) {
                    $composeArgs[] = '-f';
                    $composeArgs[] = $overlay;
                }
            }
            $process = new Process(array_merge($composeArgs, ['down']), $rootDir);
            $process->setTimeout(30);
            $process->run(function (string $type, string $buffer) use ($io): void {
                $io->write($buffer);
            });
            if ($process->isSuccessful()) {
                $io->success('Containers stopped.');
            } else {
                $io->warning('docker compose down failed or not available. Trying port/PID cleanup.');
            }
        }

        // 2. Stop by PID file (when server was started as php server.php and wrote PID)
        $pidFile = $rootDir . '/var/swoole.pid';
        if (file_exists($pidFile)) {
            $pid = trim((string) file_get_contents($pidFile));
            if ($pid !== '' && ctype_digit($pid)) {
                $io->text("Stopping process from PID file: {$pid}");
                $this->killPid((int) $pid);
            }
            @unlink($pidFile);
        }

        // 3. Kill any process still listening on the port (e.g. leftover or non-Docker run)
        $attempts = 0;
        while ($attempts < 5) {
            $pids = $this->getPidsOnPort($port);
            if (empty($pids)) {
                if ($attempts > 0) {
                    $io->success("All processes on port {$port} terminated.");
                } else {
                    $io->info("No processes found on port {$port}.");
                }
                break;
            }

            $io->text("PIDs on port {$port}: " . implode(', ', $pids));
            foreach ($pids as $pid) {
                $this->killPid($pid);
            }
            sleep(1);

            $pids = $this->getPidsOnPort($port);
            if (empty($pids)) {
                break;
            }
            foreach ($pids as $pid) {
                $this->killPid($pid, true);
            }
            sleep(1);
            $attempts++;
        }

        if ($attempts >= 5 && !empty($this->getPidsOnPort($port))) {
            $io->warning("Unable to terminate all processes on port {$port} after 5 attempts.");
            return Command::FAILURE;
        }

        $io->success('Stopped.');
        return Command::SUCCESS;
    }

    private function getPortFromEnv(string $rootDir): string
    {
        $envFile = $rootDir . '/.env';
        if (file_exists($envFile)) {
            $content = (string) file_get_contents($envFile);
            if (preg_match('/^\s*SWOOLE_PORT\s*=\s*(\d+)/m', $content, $m)) {
                return $m[1];
            }
        }
        return '9501';
    }

    private function killPid(int $pid, bool $force = false): void
    {
        $sig = $force ? SIGKILL : SIGTERM;
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $sig);
        } else {
            $opt = $force ? '-9' : '-TERM';
            exec("kill {$opt} {$pid} 2>/dev/null");
        }
    }

    private function getPidsOnPort(string $port): array
    {
        $pids = [];
        $output = shell_exec("ss -ltnp 2>/dev/null | awk -v port=\":{$port}\" '\$4 ~ port {print \$6}' | sed -n 's/.*pid=\\([0-9]*\\).*/\\1/p' | sort -u");
        if ($output) {
            foreach (explode("\n", trim($output)) as $pid) {
                if ($pid) {
                    $pids[] = (int)$pid;
                }
            }
        }
        return $pids;
    }
}

