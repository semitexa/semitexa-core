<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final class StartRuntimeAction
{
    public function __construct(private readonly SymfonyStyle $io) {}

    /**
     * Start Docker Compose containers.
     * Returns false on failure.
     */
    public function execute(?string $service = null): bool
    {
        $projectRoot = ProjectRoot::get();

        if (!file_exists($projectRoot . '/docker-compose.yml')) {
            $this->io->error('docker-compose.yml not found.');
            $this->io->text([
                'Run <comment>semitexa init</comment> to generate project structure including docker-compose.yml.',
                'See docs/RUNNING.md (or vendor/semitexa/core/docs/RUNNING.md when installed) for the supported way to run the app (Docker only).',
            ]);
            return false;
        }

        $overlayNames = $this->overlays($projectRoot);
        $composeArgs = $this->getComposeBaseArgs($overlayNames);
        if ($overlayNames !== []) {
            $this->io->text('<info>[start]</info> Using overlays: ' . implode(', ', $overlayNames));
        }

        $this->io->text('<info>[start]</info> Starting containers...');

        $upArgs = ['up', '-d'];
        if ($service !== null && $service !== '') {
            $upArgs[] = $service;
        }

        // A file left from an earlier start may name services this one does not
        // load: starting the worker with it is worse than not starting.
        $workerEnvError = $this->writeWorkerEnvFile();
        if ($workerEnvError !== null) {
            $this->io->error("The worker's service addresses could not be written ({$workerEnvError}), so it would start with stale ones.");
            return false;
        }
        $process = new Process(array_merge(['docker', 'compose'], $composeArgs, $upArgs), $projectRoot);
        $process->setTimeout(null);
        $process->run(fn(string $type, string $buffer) => $this->io->write($buffer));

        if (!$process->isSuccessful()) {
            $this->io->error('Failed to start containers.');
            return false;
        }

        $port = (int) Environment::getEnvValue('SWOOLE_PORT', '9502');
        $this->io->text("<info>[start]</info> Containers started. App: http://localhost:{$port}");

        return true;
    }

    /**
     * @return list<string>
     */
    public function getComposeArgs(): array
    {
        return $this->getComposeBaseArgs($this->overlays(ProjectRoot::get()));
    }

    /**
     * What the overlays set on the app, for the worker in
     * docker-compose.worker.yml, which reads var/run/worker.env last: that
     * file cannot name services an overlay that is not loaded does not have.
     * bin/semitexa's compose_write_worker_env() writes the same.
     *
     * @return array<string, string>
     */
    public function workerEnvironment(): array
    {
        $overlays = $this->overlays(ProjectRoot::get());
        $env = [];
        if (in_array('docker-compose.mysql.yml', $overlays, true)) {
            $env += ['DB_HOST' => 'mysql', 'DB_PORT' => '3306'];
        }
        if (in_array('docker-compose.redis.yml', $overlays, true)) {
            $env += ['REDIS_HOST' => 'redis', 'REDIS_PORT' => '6379'];
        }
        if (in_array('docker-compose.nats.yml', $overlays, true)) {
            $env['NATS_PRIMARY_URL'] = 'nats://nats:4222';
        }
        if (in_array('docker-compose.ollama.yml', $overlays, true)) {
            $env['LLM_BASE_URL'] = 'http://ollama:11434';
        }
        return $env;
    }

    /**
     * Rewritten on every start, so nothing stale is kept.
     *
     * @return string|null why it could not be written; null when it was, or the project has no worker
     */
    public function writeWorkerEnvFile(): ?string
    {
        $root = ProjectRoot::get();
        if (!file_exists($root . '/docker-compose.worker.yml')) {
            return null;
        }
        if (!is_dir($root . '/var/run') && !@mkdir($root . '/var/run', 0o775, true) && !is_dir($root . '/var/run')) {
            return "cannot create {$root}/var/run";
        }
        $lines = ["# Written by semitexa server:start: the services the overlays give the app."];
        foreach ($this->workerEnvironment() as $key => $value) {
            $lines[] = "{$key}={$value}";
        }
        $tmp = $root . '/var/run/worker.env.tmp.' . getmypid();
        if (@file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
            return "cannot write {$tmp}";
        }
        if (!@rename($tmp, $root . '/var/run/worker.env')) {
            @unlink($tmp);
            return "cannot replace {$root}/var/run/worker.env";
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function overlays(string $projectRoot): array
    {
        $async = ['1', 'true', 'yes'];

        return array_values(array_filter([
            // The worker, in a container of its own, whatever EVENTS_ASYNC says: the
            // scheduler and webhook delivery need no broker (bin/semitexa does the same).
            file_exists($projectRoot . '/docker-compose.worker.yml') ? 'docker-compose.worker.yml' : null,
            $this->shouldUseOverlay($projectRoot, 'docker-compose.nats.yml', 'EVENTS_ASYNC', $async) ? 'docker-compose.nats.yml' : null,
            $this->shouldUseOverlay($projectRoot, 'docker-compose.mysql.yml', 'DB_DRIVER', ['mysql', 'mysqli', 'mariadb']) ? 'docker-compose.mysql.yml' : null,
            $this->shouldUseOverlay($projectRoot, 'docker-compose.redis.yml', 'REDIS_HOST') ? 'docker-compose.redis.yml' : null,
            $this->shouldUseOverlayExact($projectRoot, 'docker-compose.ollama.yml', 'LLM_PROVIDER', 'ollama') ? 'docker-compose.ollama.yml' : null,
        ]));
    }

    /**
     * @param list<string> $allowedValues
     */
    private function shouldUseOverlay(string $projectRoot, string $file, string $envVar, array $allowedValues = []): bool
    {
        if (!file_exists($projectRoot . '/' . $file)) {
            return false;
        }
        $value = strtolower(trim((string) Environment::getEnvValue($envVar, '')));
        if ($allowedValues !== []) {
            return in_array($value, $allowedValues, true);
        }
        return $value !== '';
    }

    private function shouldUseOverlayExact(string $projectRoot, string $file, string $envVar, string $expected): bool
    {
        if (!file_exists($projectRoot . '/' . $file)) {
            return false;
        }
        return strtolower(trim((string) Environment::getEnvValue($envVar, ''))) === $expected;
    }

    /**
     * @param list<string> $overlays
     * @return list<string>
     */
    private function getComposeBaseArgs(array $overlays): array
    {
        if ($overlays === []) {
            return [];
        }
        $args = ['-f', 'docker-compose.yml'];
        foreach ($overlays as $overlay) {
            $args[] = '-f';
            $args[] = $overlay;
        }
        return $args;
    }
}
