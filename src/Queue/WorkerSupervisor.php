<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * Runs every worker process as a child forked from this one, which has the
 * framework loaded already. Five `php bin/semitexa <role>` processes each
 * loaded and compiled the whole framework for themselves (about 74 MB of
 * private memory each, measured on a new project); a fork shares those pages
 * with its parent until it writes to them, so a role costs what it changes.
 *
 * Each role is still a process of its own: one that crashes takes nothing
 * with it, is reported, and is started again after its pause, from this
 * process, which has run no job and holds no connection. `kill <pid>` restarts
 * one role; its title in `ps` and `docker top` is its command.
 *
 * A TERM or INT here is passed to every role, which finishes the work in hand
 * (StopSignals) and exits; this returns when the last one has. A second one
 * is passed on too, and ends them at once.
 */
final class WorkerSupervisor
{
    /** @var array<int, int> pid => index in $slots */
    private array $running = [];

    /** @var list<array{process: WorkerProcess, dueAt: float}> */
    private array $slots = [];

    private bool $stopping = false;

    /**
     * @param \Closure(list<string>): int $run runs one command in the child just forked, and returns its exit code
     * @param \Closure(string): void       $say one line for the operator
     */
    public function __construct(
        private readonly \Closure $run,
        private readonly \Closure $say,
    ) {
    }

    public static function isSupported(): bool
    {
        return extension_loaded('pcntl') && extension_loaded('posix');
    }

    /**
     * Until a TERM or INT, and then until every role has exited.
     *
     * @param list<WorkerProcess> $processes
     */
    public function supervise(array $processes): int
    {
        $signals = [SIGTERM, SIGINT, SIGCHLD];
        // Held, and read below one at a time: a child that exits between the
        // reaping and the wait is still seen, with no handler to race.
        $before = [];
        pcntl_sigprocmask(SIG_BLOCK, $signals, $before);

        try {
            $this->slots = [];
            foreach ($processes as $process) {
                $this->slots[] = ['process' => $process, 'dueAt' => 0.0];
            }
            $this->running = [];
            $this->stopping = false;

            while (true) {
                $this->reap();
                if ($this->stopping) {
                    if ($this->running === []) {
                        return 0;
                    }
                } else {
                    $this->startDue();
                }

                $signal = pcntl_sigtimedwait($signals, $info, $this->secondsToNextStart());
                if ($signal === SIGTERM || $signal === SIGINT) {
                    $this->stopping = true;
                    foreach (array_keys($this->running) as $pid) {
                        posix_kill($pid, $signal);
                    }
                }
            }
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, is_array($before) ? $before : []);
        }
    }

    private function reap(): void
    {
        $status = 0;
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            if (!isset($this->running[$pid]) || !is_int($status)) {
                continue;
            }
            $index = $this->running[$pid];
            unset($this->running[$pid]);
            if ($this->stopping) {
                continue;
            }
            $process = $this->slots[$index]['process'];
            $this->slots[$index]['dueAt'] = microtime(true) + $process->pauseSeconds;

            $ended = pcntl_wifsignaled($status)
                ? 'killed by signal ' . pcntl_wtermsig($status)
                : 'exited (' . pcntl_wexitstatus($status) . ')';
            // A command that finished its work (scheduler:plan) is not news.
            if ($ended !== 'exited (0)') {
                ($this->say)(sprintf('worker: %s %s; again in %ds', implode(' ', $process->command), $ended, $process->pauseSeconds));
            }
        }
    }

    private function startDue(): void
    {
        $now = microtime(true);
        $live = array_flip($this->running);
        foreach ($this->slots as $index => $slot) {
            if (isset($live[$index]) || $slot['dueAt'] > $now) {
                continue;
            }
            $process = $slot['process'];
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->runAsChild($process);
            }
            if ($pid < 0) {
                $this->slots[$index]['dueAt'] = $now + max(1, $process->pauseSeconds);
                ($this->say)(sprintf('worker: %s could not be started (fork failed); again in %ds', implode(' ', $process->command), max(1, $process->pauseSeconds)));
                continue;
            }
            $this->running[$pid] = $index;
        }
    }

    private function runAsChild(WorkerProcess $process): never
    {
        // Its own signals: what this process holds back, the role handles.
        pcntl_sigprocmask(SIG_SETMASK, []);
        // A session of its own, as `setsid php` gave it: a TERM sent to this
        // group does not reach what a job started (a converter, a renderer).
        posix_setsid();
        // Not its siblings' random sequence, if the parent seeded one.
        mt_srand();
        @cli_set_process_title('semitexa ' . implode(' ', $process->command));

        $code = 255;
        try {
            $code = ($this->run)($process->command);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("worker: %s failed: %s\n", implode(' ', $process->command), $e->getMessage()));
        }

        exit($code);
    }

    /** At least one: a role that is due now and could not be forked is tried again, not spun on. */
    private function secondsToNextStart(): int
    {
        if ($this->stopping) {
            return 3600;
        }
        $live = array_flip($this->running);
        $next = null;
        foreach ($this->slots as $index => $slot) {
            if (!isset($live[$index])) {
                $next = $next === null ? $slot['dueAt'] : min($next, $slot['dueAt']);
            }
        }
        if ($next === null) {
            return 3600;
        }

        return max(1, (int) ceil($next - microtime(true)));
    }
}
