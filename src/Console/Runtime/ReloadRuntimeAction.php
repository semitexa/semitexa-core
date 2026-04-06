<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ReloadRuntimeAction
{
    public function __construct(private readonly SymfonyStyle $io) {}

    /**
     * Send SIGUSR1 to Swoole master for graceful worker reload.
     * Returns false on failure.
     */
    public function execute(): bool
    {
        $this->warnIfAutoloadChanged();
        $this->warnIfEnvironmentChanged();

        $pid = $this->findMasterPid();

        if ($pid === null) {
            $this->io->error('Could not find Swoole master process. Is the server running?');
            return false;
        }

        if (!function_exists('posix_kill')) {
            $this->io->error('posix_kill() is unavailable; server reload requires the POSIX extension.');
            return false;
        }

        if (!defined('SIGUSR1')) {
            $this->io->error('SIGUSR1 signal is unavailable; server reload requires the PCNTL extension.');
            return false;
        }

        $this->io->text("<info>[reload]</info> Sending SIGUSR1 to Swoole master (PID {$pid})...");

        if (!posix_kill($pid, SIGUSR1)) {
            $this->io->error("Failed to send SIGUSR1 to PID {$pid}: " . posix_strerror(posix_get_last_error()));
            return false;
        }

        $this->io->text('<info>[reload]</info> Reload signal sent. Workers will gracefully restart.');
        return true;
    }

    private function warnIfAutoloadChanged(): void
    {
        $root = ProjectRoot::get();
        $markerFile = $root . '/var/runtime/build.hash';
        $classMapFile = $root . '/vendor/composer/autoload_classmap.php';

        if (!is_file($markerFile) || !is_file($classMapFile)) {
            return;
        }

        $markerMtime = filemtime($markerFile);
        $classMapMtime = filemtime($classMapFile);

        if ($classMapMtime > $markerMtime) {
            $this->io->warning('Autoload classmap has changed since last restart. Run server:restart for full code refresh.');
        }
    }

    private function warnIfEnvironmentChanged(): void
    {
        $root = ProjectRoot::get();
        $markerFile = $root . '/var/runtime/build.hash';

        if (!is_file($markerFile)) {
            return;
        }

        $markerMtime = filemtime($markerFile);
        if ($markerMtime === false) {
            return;
        }

        foreach ([$root . '/.env.default', $root . '/.env'] as $envFile) {
            if (!is_file($envFile)) {
                continue;
            }

            $envMtime = filemtime($envFile);
            if ($envMtime !== false && $envMtime > $markerMtime) {
                $this->io->warning('Environment files changed after the last restart. Run server:restart to pick up .env changes.');
                return;
            }
        }
    }

    private function findMasterPid(): ?int
    {
        $root = ProjectRoot::get();
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
                if ($pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0) && $this->isSwooleProcess($pid)) {
                    return $pid;
                }
            }
        }

        return null;
    }

    private function isSwooleProcess(int $pid): bool
    {
        $cmdlineFile = "/proc/{$pid}/cmdline";
        if (!is_readable($cmdlineFile)) {
            return false;
        }
        $cmdline = @file_get_contents($cmdlineFile);
        return $cmdline !== false && str_contains($cmdline, 'server.php');
    }
}
