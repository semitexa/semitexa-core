<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Runtime\PrepareRuntimeAction;
use Semitexa\Core\Console\Runtime\StartRuntimeAction;
use Semitexa\Core\Console\Runtime\VerifyRuntimeAction;
use Semitexa\Core\Environment;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lifecycle: prepare → start → verify
 */
#[AsCommand(name: 'server:start', description: 'Start Semitexa Environment (Docker Compose)')]
class ServerStartCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('server:start')
            ->setDescription('Start Semitexa Environment (Docker Compose)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Starting Semitexa Environment');

        // 1. Prepare: composer dump-autoload + build marker
        $prepare = new PrepareRuntimeAction($io);
        if (!$prepare->execute()) {
            return Command::FAILURE;
        }

        // 2. Start: docker compose up
        $start = new StartRuntimeAction($io);
        if (!$start->execute()) {
            return Command::FAILURE;
        }

        // 3. Verify: health check + build marker
        $verify = new VerifyRuntimeAction($io);
        if (!$verify->execute(checkBuildMarker: true)) {
            return Command::FAILURE;
        }

        $this->reportServiceHealth($io, $start);

        $io->success('Semitexa environment started successfully!');
        $io->text('To view logs: docker compose logs -f');
        $io->text('To stop: bin/semitexa server:stop');

        return Command::SUCCESS;
    }

    private function reportServiceHealth(SymfonyStyle $io, StartRuntimeAction $start): void
    {
        $composeArgs = $start->getComposeArgs();
        $projectRoot = \Semitexa\Core\Support\ProjectRoot::get();

        $services = [
            ['RABBITMQ_HOST', 5672, 'RABBITMQ_PORT', 'RabbitMQ', 3, 2, 1],
            ['DB_HOST', 3306, 'DB_PORT', 'MySQL', 5, 4, 20],
            ['REDIS_HOST', 6379, 'REDIS_PORT', 'Redis', 3, 2, 1],
        ];

        foreach ($services as [$hostEnv, $defaultPort, $portEnv, $name, $maxAttempts, $delay, $initialWait]) {
            $host = trim((string) Environment::getEnvValue($hostEnv, ''));
            if ($host === '') {
                continue;
            }

            $port = (int) Environment::getEnvValue($portEnv, (string) $defaultPort);
            $cmd = array_merge(
                ['docker', 'compose'],
                $composeArgs,
                ['exec', '-T', 'app', 'php', '-r',
                    sprintf(
                        '$s = @fsockopen(%s, %d, $e, $es, 3); if ($s) { fclose($s); exit(0); } exit(1);',
                        var_export($host, true),
                        $port,
                    ),
                ]
            );

            sleep($initialWait);
            $reachable = false;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($attempt > 1) {
                    sleep($delay);
                }
                $process = new \Symfony\Component\Process\Process($cmd, $projectRoot);
                $process->setTimeout(8);
                $process->run();
                if ($process->isSuccessful()) {
                    $reachable = true;
                    break;
                }
            }

            if ($reachable) {
                $io->success("{$name}: reachable.");
            } else {
                $io->warning("{$name}: not reachable after {$maxAttempts} attempts (may still be starting).");
            }
        }

        // Ollama check
        $llmProvider = strtolower(trim((string) Environment::getEnvValue('LLM_PROVIDER', '')));
        if ($llmProvider === 'ollama') {
            $cmd = array_merge(
                ['docker', 'compose'],
                $composeArgs,
                ['exec', '-T', 'app', 'php', '-r',
                    '$s = @fsockopen("ollama", 11434, $e, $es, 3); if ($s) { fclose($s); exit(0); } exit(1);',
                ]
            );
            sleep(3);
            $reachable = false;
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                if ($attempt > 1) {
                    sleep(3);
                }
                $process = new \Symfony\Component\Process\Process($cmd, $projectRoot);
                $process->setTimeout(8);
                $process->run();
                if ($process->isSuccessful()) {
                    $reachable = true;
                    break;
                }
            }
            if ($reachable) {
                $io->success('Ollama: reachable.');
            } else {
                $io->warning('Ollama: not reachable after 5 attempts (may still be starting).');
            }
        }
    }
}
