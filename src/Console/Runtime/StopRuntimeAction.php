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

        // 1. Docker Compose down/stop
        if (file_exists($projectRoot . '/docker-compose.yml')) {
            $composeArgs = array_merge(['docker', 'compose'], (new StartRuntimeAction($this->io))->getComposeArgs());
            $cmd = $service !== null
                ? array_merge($composeArgs, ['stop', $service])
                : array_merge($composeArgs, ['down', '--remove-orphans']);

            $process = new Process($cmd, $projectRoot);
            $process->setTimeout(30);
            $process->run(fn(string $type, string $buffer) => $this->io->write($buffer));

            if (!$process->isSuccessful()) {
                $this->io->warning('Docker Compose stop failed.');
                if ($service !== null) {
                    return false;
                }
                $this->io->warning('Trying PID/port cleanup...');
            }
        }

        if ($service !== null) {
            $this->io->text('<info>[stop]</info> Service stopped.');
            return true;
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
                    $pidInt = (int) $pid;
                    if (RuntimePidfile::verifyProcess($pidInt, $projectRoot)) {
                        $this->io->text("Stopping process from PID file: {$pidInt}");
                        $this->killPid($pidInt);
                    } else {
                        $this->io->warning(
                            "Ignoring stale PID file {$pidFile}: PID {$pidInt} is not a live Semitexa server process."
                        );
                    }
                }
                @unlink($pidFile);
            }
        }
        @unlink($projectRoot . RuntimePidfile::COOKIE_RELATIVE_PATH);

        // 3. Port-based cleanup
        $port = $this->resolveSwoolePort();
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
        $sig = $force ? (defined('SIGKILL') ? SIGKILL : 9) : (defined('SIGTERM') ? SIGTERM : 15);
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
    private function getPidsOnPort(int $port): array
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

    private function resolveSwoolePort(): int
    {
        $raw = Environment::getEnvValue('SWOOLE_PORT', '9502');
        $port = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535,
            ],
        ]);

        return $port !== false ? $port : 9502;
    }
}
