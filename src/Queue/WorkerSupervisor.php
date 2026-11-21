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
 *
 * What a fork costs: a role started again runs the code this process loaded,
 * not what is on disk now. So a HUP stops every role the way a TERM does and
 * then returns with reloadAsked(), for the caller to start this process anew
 * (worker:run replaces itself); and a role started again while a loaded file
 * has changed is said, once for each set of changes.
 */
final class WorkerSupervisor
{
    /** @var array<int, int> pid => index in $slots */
    private array $running = [];

    /** @var list<array{process: WorkerProcess, dueAt: float, starts: int, forkFailures: int}> */
    private array $slots = [];

    private bool $stopping = false;

    private bool $reloading = false;

    /** @var list<string>|null the changed files last said, so the same set is said once */
    private ?array $saidChanged = null;

    /** The longest a role that cannot be forked waits before the next try. */
    private const FORK_BACKOFF_MAX = 60;

    /**
     * @param \Closure(list<string>): int   $run          runs one command in the child just forked, and returns its exit code
     * @param \Closure(string): void         $say          one line for the operator
     * @param (\Closure(): list<string>)|null $changedFiles the files this process loaded that changed since (WorkerLoadedFiles)
     * @param (\Closure(): int)|null          $fork         pcntl_fork(), unless a test says otherwise
     */
    public function __construct(
        private readonly \Closure $run,
        private readonly \Closure $say,
        private readonly ?\Closure $changedFiles = null,
        private readonly ?\Closure $fork = null,
    ) {
    }

    /** Whether supervise() returned for a HUP: every role has stopped, and the caller loads the code again. */
    public function reloadAsked(): bool
    {
        return $this->reloading && !$this->stopping;
    }

    public static function isSupported(): bool
    {
        return extension_loaded('pcntl') && extension_loaded('posix');
    }

    /**
     * Until a TERM, INT or HUP, and then until every role has exited.
     *
     * @param list<WorkerProcess> $processes
     */
    public function supervise(array $processes): int
    {
        $signals = [SIGTERM, SIGINT, SIGHUP, SIGCHLD];
        // Held, and read below one at a time: a child that exits between the
        // reaping and the wait is still seen, with no handler to race.
        $before = [];
        pcntl_sigprocmask(SIG_BLOCK, $signals, $before);

        try {
            $this->slots = [];
            foreach ($processes as $process) {
                $this->slots[] = ['process' => $process, 'dueAt' => 0.0, 'starts' => 0, 'forkFailures' => 0];
            }
            $this->running = [];
            $this->stopping = false;
            $this->reloading = false;
            $this->saidChanged = null;

            // A connection open here would be every role's at once: two
            // processes reading one socket take each other's answers.
            $sockets = self::openSockets();
            if ($sockets > 0 && $this->slots !== []) {
                ($this->say)(sprintf('worker: this process holds %d open socket(s) before it forks; every role would share them. Open connections in the role, not while loading.', $sockets));
            }

            while (true) {
                $this->reap();
                if ($this->stopping || $this->reloading) {
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
                } elseif ($signal === SIGHUP && !$this->stopping && !$this->reloading) {
                    // Once: a second HUP must not end at once the roles the first asked to finish.
                    $this->reloading = true;
                    ($this->say)('worker: HUP: every role finishes the work in hand, then the worker loads the code again');
                    foreach (array_keys($this->running) as $pid) {
                        posix_kill($pid, SIGTERM);
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
            if ($this->stopping || $this->reloading) {
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
            if ($slot['starts'] > 0) {
                $this->sayChangedFiles($process);
            }
            $pid = $this->fork !== null ? ($this->fork)() : pcntl_fork();
            if ($pid === 0) {
                $this->runAsChild($process);
            }
            if ($pid < 0) {
                // Out of processes or memory: asking again at once makes it
                // worse. 1 s, 2, 4, ... up to a minute, until one succeeds.
                $wait = min(self::FORK_BACKOFF_MAX, 2 ** min(6, $slot['forkFailures']));
                $slot['forkFailures']++;
                $slot['dueAt'] = $now + $wait;
                $this->slots[$index] = $slot;
                ($this->say)(sprintf('worker: %s could not be started (fork failed); again in %ds', implode(' ', $process->command), $wait));
                continue;
            }
            $slot['forkFailures'] = 0;
            $slot['starts']++;
            $this->slots[$index] = $slot;
            $this->running[$pid] = $index;
        }
    }

    /**
     * A role started again is forked with the code loaded at the start. When
     * that is no longer what is on disk, say so: nothing else would, and the
     * role would go on running the old code after an edit or a deploy.
     */
    private function sayChangedFiles(WorkerProcess $process): void
    {
        if ($this->changedFiles === null) {
            return;
        }
        $changed = ($this->changedFiles)();
        if ($changed === [] || $changed === $this->saidChanged) {
            $this->saidChanged = $changed === [] ? null : $this->saidChanged;

            return;
        }
        $this->saidChanged = $changed;
        ($this->say)(sprintf(
            'worker: %d file(s) loaded when the worker started have changed since (%s%s): %s and every other role started again still run the code loaded then. Send HUP to this process (pid %d) or restart the worker to load them.',
            count($changed),
            implode(', ', array_slice($changed, 0, 3)),
            count($changed) > 3 ? ', ...' : '',
            implode(' ', $process->command),
            getmypid(),
        ));
    }

    /** Sockets this process has open, read from /proc; 0 where that cannot be read. */
    private static function openSockets(): int
    {
        $count = 0;
        foreach (glob('/proc/self/fd/*') ?: [] as $fd) {
            if (str_starts_with((string) @readlink($fd), 'socket:')) {
                $count++;
            }
        }

        return $count;
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
        if ($this->stopping || $this->reloading) {
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
