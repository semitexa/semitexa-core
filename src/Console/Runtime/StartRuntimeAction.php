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
    public function execute(): bool
    {
        $projectRoot = ProjectRoot::get();

        if (!file_exists($projectRoot . '/docker-compose.yml')) {
            $this->io->error('docker-compose.yml not found.');
            $this->io->text([
                'Run <comment>semitexa init</comment> to generate project structure including docker-compose.yml.',
                'See docs/RUNNING.md for the supported way to run the app (Docker only).',
            ]);
            return false;
        }

        $useRabbitMq = $this->shouldUseOverlay($projectRoot, 'docker-compose.rabbitmq.yml', 'EVENTS_ASYNC', ['1', 'true', 'yes']);
        $useMysql = $this->shouldUseOverlay($projectRoot, 'docker-compose.mysql.yml', 'DB_DRIVER');
        $useRedis = $this->shouldUseOverlay($projectRoot, 'docker-compose.redis.yml', 'REDIS_HOST');
        $useOllama = $this->shouldUseOverlayExact($projectRoot, 'docker-compose.ollama.yml', 'LLM_PROVIDER', 'ollama');

        $composeArgs = $this->getComposeBaseArgs($projectRoot, $useRabbitMq, $useMysql, $useRedis, $useOllama);

        $overlayNames = array_filter([
            $useRabbitMq ? 'docker-compose.rabbitmq.yml' : null,
            $useMysql ? 'docker-compose.mysql.yml' : null,
            $useRedis ? 'docker-compose.redis.yml' : null,
            $useOllama ? 'docker-compose.ollama.yml' : null,
        ]);
        if ($overlayNames !== []) {
            $this->io->text('<info>[start]</info> Using overlays: ' . implode(', ', $overlayNames));
        }

        $this->io->text('<info>[start]</info> Starting containers...');

        $process = new Process(array_merge(['docker', 'compose'], $composeArgs, ['up', '-d']), $projectRoot);
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
        $projectRoot = ProjectRoot::get();
        $useRabbitMq = $this->shouldUseOverlay($projectRoot, 'docker-compose.rabbitmq.yml', 'EVENTS_ASYNC', ['1', 'true', 'yes']);
        $useMysql = $this->shouldUseOverlay($projectRoot, 'docker-compose.mysql.yml', 'DB_DRIVER');
        $useRedis = $this->shouldUseOverlay($projectRoot, 'docker-compose.redis.yml', 'REDIS_HOST');
        $useOllama = $this->shouldUseOverlayExact($projectRoot, 'docker-compose.ollama.yml', 'LLM_PROVIDER', 'ollama');

        return $this->getComposeBaseArgs($projectRoot, $useRabbitMq, $useMysql, $useRedis, $useOllama);
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
     * @return list<string>
     */
    private function getComposeBaseArgs(string $projectRoot, bool $useRabbitMq, bool $useMysql, bool $useRedis, bool $useOllama): array
    {
        $overlays = array_filter([
            $useRabbitMq ? 'docker-compose.rabbitmq.yml' : null,
            $useMysql ? 'docker-compose.mysql.yml' : null,
            $useRedis ? 'docker-compose.redis.yml' : null,
            $useOllama ? 'docker-compose.ollama.yml' : null,
        ]);
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
