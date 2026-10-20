<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Queue\WorkerProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every long-running process the installed packages need, one per line:
 * "<pause> <command> [arguments]". The scaffold's worker container runs each
 * line with `bin/semitexa` and starts it again <pause> seconds after it exits.
 * Packages declare theirs with #[AsWorkerProcessSource]. A source that fails
 * is reported on stderr and the others are still listed: a broken mail config
 * must not stop the scheduler.
 */
#[AsCommand(name: 'worker:processes', description: 'List the long-running processes the installed packages need')]
class WorkerProcessesCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    protected function configure(): void
    {
        $this->setName('worker:processes')
            ->setDescription('List the long-running processes the installed packages need')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON, with the source of each process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $this->classDiscovery->initialize();
        $classes = $this->classDiscovery->findClassesWithAttribute(AsWorkerProcessSource::class);
        sort($classes);

        /** @var array<string, array{process: WorkerProcess, source: string}> $processes */
        $processes = [];
        $failed = 0;
        foreach ($classes as $class) {
            try {
                $source = $this->container->get($class);
                if (!$source instanceof WorkerProcessSourceInterface) {
                    throw new \LogicException('it does not implement ' . WorkerProcessSourceInterface::class);
                }
                foreach ($source->workerProcesses() as $process) {
                    $processes[$process->line()] ??= ['process' => $process, 'source' => $class];
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors->writeln("worker:processes: {$class} failed: " . $e->getMessage());
            }
        }

        // Read, before any is refused: a listing that only refused is not a
        // failed one, and the worker falls back to queue:work on a failed one.
        $listed = $processes !== [];
        $processes = $this->withoutSharedQueues($processes, $errors);

        if ($input->getOption('json')) {
            $rows = array_map(static fn (array $p): array => [
                'pause' => $p['process']->pauseSeconds,
                'command' => $p['process']->command,
                'source' => $p['source'],
            ], array_values($processes));
            $output->writeln((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
        } else {
            foreach (array_keys($processes) as $line) {
                $output->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }

        // Nothing at all could be read: the caller falls back to what it knows.
        return $failed > 0 && !$listed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Two kinds of worker on one queue (queue:work and mail:work, say, when a
     * listener names the mail queue) each take messages only the other can
     * read, and fail them. Neither is run, and the clash is said, so the queue
     * waits for a name of its own instead of losing messages to the wrong worker.
     *
     * @param array<string, array{process: WorkerProcess, source: string}> $processes
     * @return array<string, array{process: WorkerProcess, source: string}>
     */
    private function withoutSharedQueues(array $processes, OutputInterface $errors): array
    {
        /** @var array<string, array<string, list<string>>> $byQueue queue => command => lines */
        $byQueue = [];
        foreach ($processes as $line => $p) {
            if ($p['process']->reads !== null) {
                $byQueue[implode(' ', $p['process']->reads)][$p['process']->command[0]][] = $line;
            }
        }
        foreach ($byQueue as $queue => $commands) {
            if (count($commands) < 2) {
                continue;
            }
            $named = [];
            foreach ($commands as $lines) {
                foreach ($lines as $line) {
                    $named[] = "'" . implode(' ', $processes[$line]['process']->command) . "' (" . $processes[$line]['source'] . ')';
                    unset($processes[$line]);
                }
            }
            [$transport, $name] = explode(' ', $queue, 2);
            $errors->writeln(sprintf(
                "worker:processes: queue '%s' on %s is read by %s: each would take messages only another can read. None of them runs; give each its own queue.",
                $name,
                $transport,
                implode(' and ', $named),
            ));
        }

        return $processes;
    }
}
