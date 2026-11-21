<?php

declare(strict_types=1);

/**
 * A worker supervisor in a process of its own, for WorkerSupervisorTest and
 * WorkerRunCommandTest: php supervise.php <autoload> <log> <mode> <lines...>.
 *
 * Every role writes "<command> pid=<pid> ppid=<ppid> sid=<sid>" to the log
 * when it starts. `work` stays up until a TERM and then writes "stopped";
 * `plan` exits 0; `broken` exits 3.
 *
 * Mode `supervisor` hands the lines to WorkerSupervisor. Mode `command` runs
 * worker:run in an application whose worker:processes prints the lines, or
 * fails when the first one is "fail".
 */

use Semitexa\Core\Application\Console\Command\WorkerRunCommand;
use Semitexa\Core\Lifecycle\StopSignals;
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

file_put_contents($log, 'loaded pid=' . getmypid() . "\n", FILE_APPEND);

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
    StopSignals::install(static function () use (&$stop): void {
        $stop = true;
    });
    while (!$stop) {
        usleep(20_000);
    }
    file_put_contents($log, "stopped {$name} pid=" . getmypid() . "\n", FILE_APPEND);

    return 0;
};

if ($mode === 'supervisor') {
    $supervisor = new WorkerSupervisor($role, static function (string $line): void {
        fwrite(STDERR, $line . "\n");
    });

    exit($supervisor->supervise(WorkerRunCommand::processes($lines, static function (string $line): void {
        fwrite(STDERR, $line . "\n");
    })));
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
        if (($this->lines[0] ?? '') === 'fail') {
            return Command::FAILURE;
        }
        foreach ($this->lines as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
});
foreach (['work', 'plan', 'broken', 'queue:work'] as $name) {
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
