<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Process\Process;

class ServerStartCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('server:start')
            ->setDescription('Start Semitexa Environment (Docker Compose)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->getProjectRoot();

        $io->title('Starting Semitexa Environment (Docker)');

        if (!file_exists($projectRoot . '/docker-compose.yml')) {
            $io->error('docker-compose.yml not found.');
            $io->text([
                'Run <comment>semitexa init</comment> to generate project structure including docker-compose.yml, or add docker-compose.yml manually.',
                'See docs/RUNNING.md or vendor/semitexa/core/docs/RUNNING.md for the supported way to run the app (Docker only).',
            ]);
            return Command::FAILURE;
        }

        $useRabbitMq = $this->shouldUseRabbitMqCompose($projectRoot);
        $useMysql = $this->shouldUseMysqlCompose($projectRoot);
        $useRedis = $this->shouldUseRedisCompose($projectRoot);
        $composeArgs = $this->getComposeBaseArgs($projectRoot, $useRabbitMq, $useMysql, $useRedis);

        $io->section('Starting containers...');

        $overlays = [];
        if ($useRabbitMq) {
            $overlays[] = 'docker-compose.rabbitmq.yml (EVENTS_ASYNC=1)';
        }
        if ($useMysql) {
            $overlays[] = 'docker-compose.mysql.yml (DB_DRIVER set)';
        }
        if ($useRedis) {
            $overlays[] = 'docker-compose.redis.yml (REDIS_HOST set)';
        }
        if ($overlays !== []) {
            $io->text('Using docker-compose.yml + ' . implode(' + ', $overlays) . '.');
        }

        $process = new Process(array_merge(['docker', 'compose'], $composeArgs, ['up', '-d']), $projectRoot);
        $process->setTimeout(null);

        $process->run(function ($type, $buffer) use ($io) {
            $io->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $io->error('Failed to start environment.');
            return Command::FAILURE;
        }

        $io->success('Semitexa environment started successfully!');

        $port = 9502;
        $eventsAsync = '0';
        if (file_exists($projectRoot . '/.env')) {
            $envContent = file_get_contents($projectRoot . '/.env');
            if ($envContent !== false) {
                if (preg_match('/^\s*SWOOLE_PORT\s*=\s*(\d+)/m', $envContent, $m)) {
                    $port = (int) $m[1];
                }
                if (preg_match('/^\s*EVENTS_ASYNC\s*=\s*(\S+)/m', $envContent, $m)) {
                    $eventsAsync = trim($m[1]);
                }
            }
        }
        $io->note('App: http://localhost:' . $port);
        $io->text('To view logs: docker compose logs -f');
        $io->text('To stop: bin/semitexa server:stop (or docker compose down)');

        $this->reportRabbitMqStatus($io, $projectRoot, $eventsAsync, $useRabbitMq, $composeArgs);
        $this->reportMysqlStatus($io, $projectRoot, $useMysql, $composeArgs);
        $this->reportRedisStatus($io, $projectRoot, $useRedis, $composeArgs);

        return Command::SUCCESS;
    }

    private function shouldUseRabbitMqCompose(string $projectRoot): bool
    {
        $rabbitMqCompose = $projectRoot . '/docker-compose.rabbitmq.yml';
        if (!file_exists($rabbitMqCompose)) {
            return false;
        }
        $envFile = $projectRoot . '/.env';
        if (!file_exists($envFile)) {
            return false;
        }
        $content = file_get_contents($envFile);
        return $content !== false && (bool) preg_match('/^\s*EVENTS_ASYNC\s*=\s*(1|true|yes)\s*$/mi', $content);
    }

    private function shouldUseMysqlCompose(string $projectRoot): bool
    {
        if (!file_exists($projectRoot . '/docker-compose.mysql.yml')) {
            return false;
        }
        $envFile = $projectRoot . '/.env';
        if (!file_exists($envFile)) {
            return false;
        }
        $content = file_get_contents($envFile);
        return $content !== false && (bool) preg_match('/^\s*DB_DRIVER\s*=\s*\S+/m', $content);
    }

    private function shouldUseRedisCompose(string $projectRoot): bool
    {
        if (!file_exists($projectRoot . '/docker-compose.redis.yml')) {
            return false;
        }
        $envFile = $projectRoot . '/.env';
        if (!file_exists($envFile)) {
            return false;
        }
        $content = file_get_contents($envFile);
        return $content !== false && (bool) preg_match('/^\s*REDIS_HOST\s*=\s*\S+/m', $content);
    }

    /**
     * @return list<string>
     */
    private function getComposeBaseArgs(string $projectRoot, bool $useRabbitMq, bool $useMysql = false, bool $useRedis = false): array
    {
        $overlays = [];
        if ($useRabbitMq && file_exists($projectRoot . '/docker-compose.rabbitmq.yml')) {
            $overlays[] = 'docker-compose.rabbitmq.yml';
        }
        if ($useMysql && file_exists($projectRoot . '/docker-compose.mysql.yml')) {
            $overlays[] = 'docker-compose.mysql.yml';
        }
        if ($useRedis && file_exists($projectRoot . '/docker-compose.redis.yml')) {
            $overlays[] = 'docker-compose.redis.yml';
        }
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

    /**
     * @param list<string> $composeArgs
     */
    private function reportRabbitMqStatus(SymfonyStyle $io, string $projectRoot, string $eventsAsync, bool $useRabbitMqCompose, array $composeArgs): void
    {
        $enabled = in_array(strtolower(trim($eventsAsync)), ['1', 'true', 'yes'], true);
        if (!$enabled || !$useRabbitMqCompose) {
            return;
        }

        $cmd = array_merge(
            ['docker', 'compose'],
            $composeArgs,
            ['exec', '-T', 'app',
            'php', '-r',
            '$h = getenv("RABBITMQ_HOST") ?: "127.0.0.1"; $p = (int)(getenv("RABBITMQ_PORT") ?: "5672"); $s = @fsockopen($h, $p, $err, $errstr, 3); if ($s) { fclose($s); exit(0); } exit(1);',
            ]
        );
        $maxAttempts = 3;
        $delaySeconds = 2;
        $reachable = false;

        sleep(1);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                sleep($delaySeconds);
            }
            $check = new Process($cmd, $projectRoot);
            $check->setTimeout(8);
            $check->run();
            if ($check->isSuccessful()) {
                $reachable = true;
                break;
            }
        }

        if ($reachable) {
            $io->success('RabbitMQ: reachable (queued events will be sent to the queue).');
        } else {
            $io->warning([
                'RabbitMQ: not reachable after ' . $maxAttempts . ' attempts (network may still be starting).',
                'Queued events will run synchronously. If the rabbitmq service is in docker-compose, try again in a few seconds or set EVENTS_ASYNC=0 in .env to disable queue usage.',
            ]);
        }
    }

    /**
     * @param list<string> $composeArgs
     */
    private function reportMysqlStatus(SymfonyStyle $io, string $projectRoot, bool $useMysqlCompose, array $composeArgs): void
    {
        if (!$useMysqlCompose) {
            return;
        }

        $cmd = array_merge(
            ['docker', 'compose'],
            $composeArgs,
            ['exec', '-T', 'app',
            'php', '-r',
            '$h = getenv("DB_HOST") ?: "127.0.0.1"; $p = (int)(getenv("DB_PORT") ?: "3306"); $s = @fsockopen($h, $p, $err, $errstr, 3); if ($s) { fclose($s); exit(0); } exit(1);',
            ]
        );
        $maxAttempts = 5;
        $delaySeconds = 4;
        $reachable = false;

        // MySQL healthcheck has start_period=30s; wait before first attempt so container can become healthy
        sleep(20);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                sleep($delaySeconds);
            }
            $check = new Process($cmd, $projectRoot);
            $check->setTimeout(8);
            $check->run();
            if ($check->isSuccessful()) {
                $reachable = true;
                break;
            }
        }

        if ($reachable) {
            $io->success('MySQL: reachable.');
        } else {
            $io->warning([
                'MySQL: not reachable after ' . $maxAttempts . ' attempts (container may still be starting).',
                'The healthcheck has start_period=30s. Try again in a few seconds or run: bin/semitexa orm:status',
            ]);
        }
    }

    /**
     * @param list<string> $composeArgs
     */
    private function reportRedisStatus(SymfonyStyle $io, string $projectRoot, bool $useRedisCompose, array $composeArgs): void
    {
        if (!$useRedisCompose) {
            return;
        }

        $cmd = array_merge(
            ['docker', 'compose'],
            $composeArgs,
            ['exec', '-T', 'app',
            'php', '-r',
            '$h = getenv("REDIS_HOST") ?: "127.0.0.1"; $p = (int)(getenv("REDIS_PORT") ?: "6379"); $s = @fsockopen($h, $p, $err, $errstr, 3); if ($s) { fclose($s); exit(0); } exit(1);',
            ]
        );
        $maxAttempts = 3;
        $delaySeconds = 2;
        $reachable = false;

        sleep(1);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                sleep($delaySeconds);
            }
            $check = new Process($cmd, $projectRoot);
            $check->setTimeout(8);
            $check->run();
            if ($check->isSuccessful()) {
                $reachable = true;
                break;
            }
        }

        if ($reachable) {
            $io->success('Redis: reachable.');
        } else {
            $io->warning([
                'Redis: not reachable after ' . $maxAttempts . ' attempts (container may still be starting).',
                'Try again in a few seconds.',
            ]);
        }
    }
}
