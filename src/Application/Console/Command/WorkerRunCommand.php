<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Queue\WorkerListingOutput;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Core\Queue\WorkerSupervisor;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the scaffold's worker container runs: every line of `worker:processes`,
 * each as a child forked from this process, started again after its pause when
 * it exits (WorkerSupervisor). The framework is loaded once, here, instead of
 * once in every role.
 *
 * This process only loads and forks. The listing is read in a child too, so
 * whatever a source opens to answer (a connection, say) is gone with it and
 * is never shared by the roles.
 */
#[AsCommand(name: 'worker:run', description: 'Run every process worker:processes lists, each restarted after its pause')]
class WorkerRunCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('worker:run')
            ->setDescription('Run every process worker:processes lists, each restarted after its pause');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $say = static function (string $line) use ($errors): void {
            $errors->writeln($line, OutputInterface::OUTPUT_RAW);
        };

        $application = $this->getApplication();
        if ($application === null || !WorkerSupervisor::isSupported()) {
            $say('worker: worker:run needs the pcntl and posix extensions (the scaffold image has them).');

            return Command::FAILURE;
        }

        $run = static function (array $command) use ($application): int {
            $application->setAutoExit(false);
            $argv = ['semitexa'];
            foreach ($command as $word) {
                if (is_string($word)) {
                    $argv[] = $word;
                }
            }

            return $application->run(new ArgvInput($argv));
        };

        $lines = self::listed($application);
        if ($lines === null) {
            // A listing that could not be read: the default queue is still consumed.
            $say('worker: worker:processes failed; running queue:work on the default queue only (server:restart retries)');
            $lines = ['5 queue:work'];
        }

        $processes = self::processes($lines, $say);
        if ($processes === []) {
            // Listed, and nothing to start (worker:processes refused a shared
            // queue, say): a queue:work here would bring back what it refused.
            // Up, or the container would be restarted to list nothing again.
            $say('worker: worker:processes listed nothing to run; idle until server:restart');
        }

        return (new WorkerSupervisor($run, $say))->supervise($processes);
    }

    /**
     * What `worker:processes` prints, read in a child of this process.
     *
     * @return list<string>|null null when it failed
     */
    private static function listed(Application $application): ?array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            return null;
        }
        [$read, $write] = $pair;

        $pid = pcntl_fork();
        if ($pid < 0) {
            return null;
        }
        if ($pid === 0) {
            fclose($read);
            $application->setAutoExit(false);
            $code = 255;
            try {
                $code = $application->run(new ArgvInput(['semitexa', 'worker:processes']), new WorkerListingOutput($write));
            } catch (\Throwable $e) {
                fwrite(STDERR, 'worker: worker:processes failed: ' . $e->getMessage() . "\n");
            }
            exit($code);
        }

        fclose($write);
        $printed = (string) stream_get_contents($read);
        fclose($read);
        $status = 0;
        pcntl_waitpid($pid, $status);
        if (!is_int($status) || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $printed)), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param list<string>           $lines "<pause> <command> [arguments]"
     * @param \Closure(string): void $say
     * @return list<WorkerProcess>
     */
    public static function processes(array $lines, \Closure $say): array
    {
        $processes = [];
        foreach ($lines as $line) {
            $words = explode(' ', $line);
            $pause = array_shift($words);
            try {
                if (preg_match('/^[0-9]+$/', $pause) !== 1 || $words === []) {
                    throw new \InvalidArgumentException('no pause, or no command');
                }
                $processes[$line] ??= new WorkerProcess($words, (int) $pause);
            } catch (\InvalidArgumentException) {
                // A warning printed among the lines, say: named, never run.
                $say("worker: skipped, not a '<pause> <command>' line: {$line}");
            }
        }

        return array_values($processes);
    }
}
