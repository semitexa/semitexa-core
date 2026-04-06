<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final class StopRuntimeAction
{
    public function __construct(private readonly SymfonyStyle $io) {}

    public function execute(?string $service = null): bool
    {
        $projectRoot = ProjectRoot::get();

        $this->io->text('<info>[stop]</info> Stopping runtime...');

        // 1. Docker Compose down
        if (file_exists($projectRoot . '/docker-compose.yml')) {
            $composeArgs = ['docker', 'compose'];
            $overlays = [
                'docker-compose.rabbitmq.yml',
                'docker-compose.mysql.yml',
                'docker-compose.redis.yml',
                'docker-compose.ollama.yml',
            ];
            $activeOverlays = array_filter($overlays, fn(string $f) => file_exists($projectRoot . '/' . $f));
            if ($activeOverlays !== []) {
                $composeArgs[] = '-f';
                $composeArgs[] = 'docker-compose.yml';
                foreach ($activeOverlays as $overlay) {
                    $composeArgs[] = '-f';
                    $composeArgs[] = $overlay;
                }
            }

            $cmd = array_merge($composeArgs, ['down']);
            if ($service !== null) {
                // For service-specific stop, use 'stop' instead of 'down'
                $cmd = array_merge($composeArgs, ['stop', $service]);
            }

            $process = new Process($cmd, $projectRoot);
            $process->setTimeout(30);
            $process->run(fn(string $type, string $buffer) => $this->io->write($buffer));

            if (!$process->isSuccessful()) {
                $this->io->warning('Docker Compose stop failed. Trying PID/port cleanup...');
            }
        }

        // 2. PID file cleanup
        $pidFiles = [
            $projectRoot . '/var/swoole.pid',
            $projectRoot . '/var/run/semitexa.pid',
        ];
        foreach ($pidFiles as $pidFile) {
            if (file_exists($pidFile)) {
                $pid = trim((string) file_get_contents($pidFile));
                if ($pid !== '' && ctype_digit($pid)) {
                    $this->io->text("Stopping process from PID file: {$pid}");
                    $this->killPid((int) $pid);
                }
                @unlink($pidFile);
            }
        }

        // 3. Port-based cleanup
        $port = (string) Environment::getEnvValue('SWOOLE_PORT', '9501');
        $attempts = 0;
        while ($attempts < 5) {
            $pids = $this->getPidsOnPort($port);
            if (empty($pids)) {
                break;
            }

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
            $this->io->error("Unable to terminate all processes on port {$port} after 5 attempts.");
            return false;
        }

        $this->io->text('<info>[stop]</info> Runtime stopped.');
        return true;
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

    /**
     * @return list<int>
     */
    private function getPidsOnPort(string $port): array
    {
        $pids = [];
        $output = shell_exec("ss -ltnp 2>/dev/null | awk -v port=\":{$port}\" '\$4 ~ port {print \$6}' | sed -n 's/.*pid=\\([0-9]*\\).*/\\1/p' | sort -u");
        if ($output) {
            foreach (explode("\n", trim($output)) as $pid) {
                if ($pid) {
                    $pids[] = (int) $pid;
                }
            }
        }
        return $pids;
    }
}
