<?php

declare(strict_types=1);

/**
 * A worker supervisor in a process of its own, for WorkerSupervisorTest and
 * WorkerRunCommandTest: php supervise.php <autoload> <log> <mode> <lines...>.
 *
 * Every role writes "<command> pid=<pid> ppid=<ppid> sid=<sid>" to the log
 * when it starts. `work` stays up until a TERM and then writes "stopped";
 * `plan` exits 0; `broken` exits 3; `slow` is in a job that a TERM does not
 * end: it writes "asked" and works on; `job` holds a job that takes one more
 * second after the TERM, and writes "stopped" when it is done.
 *
 * Mode `supervisor` hands the lines to WorkerSupervisor. Mode `command` runs
 * worker:run in an application whose worker:processes prints the lines, or
 * fails when the first one is "fail"; it opens a socket to answer, as a source
 * that asks a database would.
 *
 * From the environment, for mode `supervisor`: WK_SOCKET=1 holds a socket open
 * before the roles are forked; WK_FORK_FAIL=<n> makes the first n forks fail;
 * WK_WATCH=<file> is loaded first and watched for changes (WorkerLoadedFiles).
 * Every start of this script writes "loaded pid=<pid> mem=<memory_limit>
 * put=<WK_PUT>" and then sets WK_PUT in its environment: a process started
 * anew does not see it.
 */

use Semitexa\Core\Application\Console\Command\WorkerRunCommand;
use Semitexa\Core\Lifecycle\StopSignals;
use Semitexa\Core\Queue\WorkerLoadedFiles;
use Semitexa\Core\Queue\WorkerSupervisor;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

require $argv[1];

$log = $argv[2];
$mode = $argv[3];
$lines = array_slice($argv, 4);

file_put_contents($log, sprintf("loaded pid=%d mem=%s put=%s\n", getmypid(), (string) ini_get('memory_limit'), (string) getenv('WK_PUT')), FILE_APPEND);
putenv('WK_PUT=1');

$role = static function (array $command) use ($log): int {
    $name = implode(' ', $command);
    file_put_contents($log, sprintf("%s pid=%d ppid=%d sid=%d\n", $name, getmypid(), posix_getppid(), posix_getsid(0)), FILE_APPEND);
    if ($command[0] === 'plan') {
        return 0;
    }
    if ($command[0] === 'broken') {
        return 3;
    }
    $stop = false;
    $slow = $command[0] === 'slow';
    StopSignals::install(static function () use (&$stop, $slow, $log, $name): void {
        if ($slow) {
            file_put_contents($log, "asked {$name} pid=" . getmypid() . "\n", FILE_APPEND);

            return;
        }
        $stop = true;
    });
    while (!$stop) {
        usleep(20_000);
    }
    if ($command[0] === 'job') {
        // The job in hand: a signal that ends the process now loses it.
        $until = microtime(true) + 1.0;
        while (microtime(true) < $until) {
            usleep(20_000);
        }
    }
    file_put_contents($log, "stopped {$name} pid=" . getmypid() . "\n", FILE_APPEND);

    return 0;
};

if ($mode === 'supervisor') {
    $say = static function (string $line): void {
        fwrite(STDERR, $line . "\n");
    };

    $changed = null;
    $watch = (string) getenv('WK_WATCH');
    if ($watch !== '') {
        require $watch;
        $started = $_SERVER['REQUEST_TIME'] ?? null;
        $changed = (new WorkerLoadedFiles([$watch], is_int($started) ? $started : time()))->changed(...);
    }

    $fork = null;
    $failures = (int) getenv('WK_FORK_FAIL');
    if ($failures > 0) {
        $fork = static function () use (&$failures): int {
            return $failures-- > 0 ? -1 : pcntl_fork();
        };
    }

    $held = getenv('WK_SOCKET') === '1' ? stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0) : null;

    $supervisor = new WorkerSupervisor($role, $say, $changed, $fork);
    $code = $supervisor->supervise(WorkerRunCommand::processes($lines, $say));
    if ($supervisor->reloadAsked()) {
        WorkerRunCommand::replaceThisProcess();
    }

    exit($code);
}

$application = new Application();
$application->add(new class($lines) extends Command {
    /** @param list<string> $lines */
    public function __construct(private readonly array $lines)
    {
        parent::__construct('worker:processes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errors->writeln('worker:processes: a source failed');
        // Kept open to the end of this process, as a pooled connection is.
        $GLOBALS['wkSourceConnection'] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if (($this->lines[0] ?? '') === 'fail') {
            return Command::FAILURE;
        }
        foreach ($this->lines as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
});
foreach (['work', 'plan', 'broken', 'slow', 'job', 'queue:work'] as $name) {
    $application->add(new class($name, $role) extends Command {
        /** @param \Closure(list<string>): int $run */
        public function __construct(private readonly string $role, private readonly \Closure $run)
        {
            parent::__construct($role);
        }

        protected function configure(): void
        {
            $this->addArgument('words', InputArgument::IS_ARRAY);
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            /** @var list<string> $words */
            $words = $input->getArgument('words');

            return ($this->run)([$this->role, ...$words]);
        }
    });
}
$application->add(new WorkerRunCommand());

exit($application->run(new ArgvInput(['semitexa', 'worker:run'])));
