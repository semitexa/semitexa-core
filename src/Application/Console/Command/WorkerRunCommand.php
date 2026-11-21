<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Queue\WorkerListingOutput;
use Semitexa\Core\Queue\WorkerLoadedFiles;
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
 *
 * A role started again runs the code loaded here, not what is on disk. HUP
 * makes this process load it again: every role finishes the work in hand, as
 * for docker stop, and the process replaces itself with a new one of the same
 * command line and environment. Until then, a role started again while a
 * loaded file has changed is named in the log (WorkerLoadedFiles).
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

        // Before the roles: what they are forked with is what is loaded now.
        $started = $_SERVER['REQUEST_TIME'] ?? null;
        $loaded = new WorkerLoadedFiles(get_included_files(), is_int($started) ? $started : time(), $this->getProjectRoot());

        $processes = self::processes($lines, $say);
        if ($processes === []) {
            // Listed, and nothing to start (worker:processes refused a shared
            // queue, say): a queue:work here would bring back what it refused.
            // Up, or the container would be restarted to list nothing again.
            $say('worker: worker:processes listed nothing to run; idle until server:restart');
        }

        $supervisor = new WorkerSupervisor($run, $say, $loaded->changed(...));
        $code = $supervisor->supervise($processes);
        if (!$supervisor->reloadAsked()) {
            return $code;
        }

        self::replaceThisProcess();
        // Still here: the container's restart policy loads the code again instead.
        $say('worker: could not start worker:run again after HUP; exiting so the worker is restarted');

        return Command::FAILURE;
    }

    /**
     * This process again, from nothing: the same php, options and arguments
     * (`php -d memory_limit=... vendor/bin/semitexa worker:run`), and the
     * environment it was started with, not the one the boot added to: a value
     * read from .env into the environment would otherwise outlive an edit of
     * .env. The pid stays, so the container and its init see no exit.
     * Returns only when it could not be done.
     */
    public static function replaceThisProcess(): void
    {
        $arguments = self::nulSeparated('/proc/self/cmdline') ?? [];
        if (count($arguments) < 2) {
            $arguments = [PHP_BINARY];
            foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $word) {
                if (is_string($word)) {
                    $arguments[] = $word;
                }
            }
        }
        array_shift($arguments);

        $pairs = self::nulSeparated('/proc/self/environ');
        if ($pairs === null) {
            // Not readable: the environment as it is now is the nearest there is.
            pcntl_exec(PHP_BINARY, $arguments);

            return;
        }
        // Read, and possibly empty: a process started with no environment is
        // started again with none, not with what its boot put there.
        $environment = [];
        foreach ($pairs as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ($name !== '') {
                $environment[$name] = $value;
            }
        }
        pcntl_exec(PHP_BINARY, $arguments, $environment);
    }

    /** @return list<string>|null null when the file could not be read; an empty file is an empty list */
    private static function nulSeparated(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }

        return array_values(array_filter(explode("\0", $raw), static fn (string $part): bool => $part !== ''));
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
