<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ReloadRuntimeAction
{
    public function __construct(private readonly SymfonyStyle $io) {}

    /** No pidfile at all: there is nothing running, and nothing to reload. */
    public const PRESENCE_ABSENT = 'absent';

    /** A verified master PID: a reload can be signalled. */
    public const PRESENCE_RUNNING = 'running';

    /**
     * A pidfile exists but could not be read or verified. Something may well be
     * running; we simply cannot tell.
     */
    public const PRESENCE_UNKNOWN = 'unknown';

    /**
     * Whether there is a Swoole master to signal — and, when there is not, why.
     *
     * Deliberately three-valued. `findMasterPid()` answers null both for "no
     * server" and for "there is a pidfile but it is unreadable, or the process
     * behind it cannot be verified", and a caller that folds those together
     * reports success without reloading anything: the operator is told nothing
     * holds a compiled template while a live worker still does.
     */
    public function serverPresence(): string
    {
        if ($this->findMasterPid() !== null) {
            return self::PRESENCE_RUNNING;
        }

        foreach ($this->pidfileCandidates() as $path) {
            if (file_exists($path)) {
                return self::PRESENCE_UNKNOWN;
            }
        }

        return self::PRESENCE_ABSENT;
    }

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

    /** @return list<string> */
    private function pidfileCandidates(): array
    {
        $root = ProjectRoot::get();

        return [
            $root . '/var/run/semitexa.pid',
            $root . '/var/swoole.pid',
        ];
    }

    private function findMasterPid(): ?int
    {
        $root = ProjectRoot::get();

        foreach ($this->pidfileCandidates() as $path) {
            if (is_readable($path)) {
                $pidRaw = file_get_contents($path);
                if ($pidRaw === false) {
                    continue;
                }

                $pid = (int) trim($pidRaw);
                if ($pid > 0 && RuntimePidfile::verifyProcess($pid, $root)) {
                    return $pid;
                }
            }
        }

        return null;
    }
}
