<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tk-worker-roles-only-what-the-project-uses. The worker container started
 * one `php bin/semitexa <role>` for each of its five roles, and each loaded
 * the whole framework for itself: about 74 MB of private memory a role, 369 MB
 * at rest, against 33 MB for the one process of the framework it is compared
 * with. worker:run loads it once and forks the roles from that process.
 *
 * What a role is did not change, and these hold it: a process of its own,
 * restarted after its pause, reported when it fails, untouched when another
 * dies, and stopped by a TERM after the work in hand.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class WorkerSupervisorTest extends TestCase
{
    private string $log = '';

    /** @var resource|null */
    private $process = null;

    private int $pid = 0;

    protected function setUp(): void
    {
        $this->log = sys_get_temp_dir() . '/semitexa-worker-supervisor-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            foreach ($this->started() as $role) {
                @posix_kill($role['pid'], SIGKILL);
            }
            @posix_kill($this->pid, SIGKILL);
            proc_close($this->process);
        }
        @unlink($this->log);
        @unlink($this->log . '.err');
        @unlink($this->log . '.watched.php');
    }

    #[Test]
    public function every_role_is_forked_from_the_one_process_that_loaded_the_framework(): void
    {
        $this->supervise('supervisor', ['5 work nats events', '5 work nats mail', '5 work default']);
        $roles = $this->waitForStarts(3);

        self::assertSame(['work default', 'work nats events', 'work nats mail'], self::sorted(array_column($roles, 'command')));
        self::assertCount(3, array_unique(array_column($roles, 'pid')), 'a process each');
        foreach ($roles as $role) {
            self::assertSame($this->pid, $role['ppid'], "{$role['command']} is a child of the supervisor, not a php started for it");
            self::assertSame($role['pid'], $role['sid'], "{$role['command']} leads a session of its own, as `setsid php` gave it");
            self::assertStringContainsString(
                'semitexa ' . $role['command'],
                str_replace("\0", ' ', (string) file_get_contents("/proc/{$role['pid']}/cmdline")),
                'ps and docker top name the role',
            );
        }
        self::assertSame(1, substr_count($this->logged(), 'loaded pid='), 'the framework is loaded once, not once a role');
    }

    /** scheduler:plan does its work and exits: started again, and not news. A failure is. */
    #[Test]
    public function a_role_that_exits_is_started_again_after_its_pause_and_only_a_failure_is_reported(): void
    {
        $this->supervise('supervisor', ['0 plan', '1 broken']);
        $this->waitFor(fn (): bool => count($this->started('plan')) >= 3 && count($this->started('broken')) >= 2);

        $broken = $this->started('broken');
        self::assertNotSame($broken[0]['pid'], $broken[1]['pid'], 'a new process each time');
        $stderr = $this->stderr();
        self::assertStringContainsString("worker: broken exited (3); again in 1s\n", $stderr);
        self::assertStringNotContainsString('plan exited', $stderr);
    }

    /** One role killed (out of memory, a segfault) takes nothing with it, is said, and comes back. */
    #[Test]
    public function a_killed_role_is_reported_and_started_again_and_the_others_never_notice(): void
    {
        $this->supervise('supervisor', ['0 work mail', '5 work queue']);
        $roles = $this->waitForStarts(2);
        $mail = self::role($roles, 'work mail');
        $queue = self::role($roles, 'work queue');

        posix_kill($mail['pid'], SIGKILL);
        $this->waitFor(fn (): bool => count($this->started('work mail')) === 2);

        self::assertStringContainsString("worker: work mail killed by signal 9; again in 0s\n", $this->stderr());
        self::assertNotSame($mail['pid'], $this->started('work mail')[1]['pid']);
        self::assertSame($this->pid, $this->started('work mail')[1]['ppid'], 'started again by the supervisor');
        self::assertTrue(posix_kill($queue['pid'], 0), 'the other role is still running');
        self::assertCount(1, $this->started('work queue'), 'and was not restarted');
        self::assertTrue(posix_kill($this->pid, 0), 'the supervisor too');
    }

    /** `kill <pid>` is how an operator restarts one role: it finishes the work in hand and is started again. */
    #[Test]
    public function a_term_to_one_role_restarts_that_role_only(): void
    {
        $this->supervise('supervisor', ['0 work mail', '5 work queue']);
        $roles = $this->waitForStarts(2);

        posix_kill(self::role($roles, 'work mail')['pid'], SIGTERM);
        $this->waitFor(fn (): bool => count($this->started('work mail')) === 2);

        self::assertStringContainsString('stopped work mail pid=' . self::role($roles, 'work mail')['pid'], $this->logged());
        self::assertCount(1, $this->started('work queue'));
        self::assertSame('', $this->stderr(), 'it finished and exited 0: not news');
    }

    /**
     * docker stop sends TERM to the supervisor alone. Every role gets it,
     * finishes the work in hand, and the supervisor exits 0 once they have:
     * none is started again, none is left behind.
     */
    #[Test]
    public function a_term_to_the_supervisor_alone_stops_every_role_after_the_work_in_hand(): void
    {
        $this->supervise('supervisor', ['0 work nats events', '0 work nats mail']);
        $roles = $this->waitForStarts(2);

        posix_kill($this->pid, SIGTERM);
        $exit = $this->waitForExit();

        self::assertSame(0, $exit);
        $logged = $this->logged();
        foreach ($roles as $role) {
            self::assertStringContainsString("stopped {$role['command']} pid={$role['pid']}", $logged, 'it finished what it held');
            self::assertFalse(@posix_kill($role['pid'], 0), "{$role['command']} does not outlive the supervisor");
        }
        self::assertCount(2, $this->started(), 'a role that stopped for the TERM is not started again');
    }

    /** Nothing listed: it stays up (or the container restarts forever to list nothing) and goes on a TERM. */
    #[Test]
    public function with_nothing_to_run_it_stays_up_until_a_term(): void
    {
        $this->supervise('supervisor', []);
        usleep(400_000);
        self::assertTrue($this->running());

        posix_kill($this->pid, SIGTERM);

        self::assertSame(0, $this->waitForExit());
    }

    /**
     * worker:run itself: the lines come from worker:processes, read in a
     * child; what that says on stderr is not taken for a line; a line that is
     * not one is named and not run.
     */
    #[Test]
    public function worker_run_starts_every_line_worker_processes_prints(): void
    {
        $this->supervise('command', ['5 work nats events', '60 plan', 'PHP Deprecated: something', '5 work *']);
        $this->waitFor(fn (): bool => count($this->started()) >= 2);
        usleep(200_000);

        self::assertSame(['plan', 'work nats events'], self::sorted(array_column($this->started(), 'command')));
        foreach ($this->started() as $role) {
            self::assertSame($this->pid, $role['ppid']);
        }
        $stderr = $this->stderr();
        self::assertStringContainsString("worker:processes: a source failed\n", $stderr, 'what the listing said is still shown');
        self::assertStringContainsString("worker: skipped, not a '<pause> <command>' line: PHP Deprecated: something\n", $stderr);
        self::assertStringContainsString("worker: skipped, not a '<pause> <command>' line: 5 work *\n", $stderr);
        self::assertStringNotContainsString('line: worker:processes: a source failed', $stderr);

        posix_kill($this->pid, SIGTERM);
        self::assertSame(0, $this->waitForExit());
    }

    /** A listing that failed still gets the default queue consumed, as the shell loop did. */
    #[Test]
    public function when_worker_processes_fails_worker_run_consumes_the_default_queue(): void
    {
        $this->supervise('command', ['fail']);
        $roles = $this->waitForStarts(1);

        self::assertSame(['queue:work'], array_column($roles, 'command'));
        self::assertStringContainsString('worker: worker:processes failed; running queue:work on the default queue only', $this->stderr());
    }

    /** Listed, and nothing to start: no queue:work is brought back, and it says so. */
    #[Test]
    public function when_worker_processes_lists_nothing_worker_run_starts_nothing_and_says_so(): void
    {
        $this->supervise('command', []);
        $this->waitFor(fn (): bool => str_contains($this->stderr(), 'idle until server:restart'));

        self::assertSame([], $this->started());
        self::assertStringContainsString("worker: worker:processes listed nothing to run; idle until server:restart\n", $this->stderr());
        self::assertTrue($this->running());
    }

    private function running(): bool
    {
        self::assertIsResource($this->process);

        return proc_get_status($this->process)['running'];
    }

    /**
     * The supervisor loads and forks, and nothing else: a connection it held
     * would be every role's at once. worker:processes may open one to answer
     * (the fixture's does), so it is asked in a child that takes it away.
     */
    #[Test]
    public function what_worker_processes_opened_reaches_neither_the_supervisor_nor_a_role(): void
    {
        $this->supervise('command', ['5 work nats events', '5 work nats mail']);
        $roles = $this->waitForStarts(2);

        self::assertSame([], self::sockets($this->pid), 'the supervisor holds no connection');
        foreach ($roles as $role) {
            self::assertSame([], self::sockets($role['pid']), "{$role['command']} was forked with none");
        }
        self::assertStringContainsString("worker:processes: a source failed\n", $this->stderr(), 'the listing ran, and opened its socket');
        self::assertStringNotContainsString('open socket', $this->stderr());
    }

    /** A boot that leaves a connection open would hand it to every role: it is said before the first fork. */
    #[Test]
    public function a_socket_held_when_the_roles_are_forked_is_said(): void
    {
        $this->supervise('supervisor', ['5 work a'], ['WK_SOCKET' => '1']);
        $this->waitForStarts(1);

        self::assertStringContainsString(
            "worker: this process holds 2 open socket(s) before it forks; every role would share them. Open connections in the role, not while loading.\n",
            $this->stderr(),
        );
    }

    /**
     * A role in a job that does not end is asked once and works on; the
     * second TERM is passed on as well and ends it at once, as Ctrl-C twice
     * does for one worker, and the supervisor still exits 0 with nothing left.
     */
    #[Test]
    public function a_second_term_ends_a_role_that_is_still_in_its_job(): void
    {
        $this->supervise('supervisor', ['5 slow job', '5 work quick']);
        $roles = $this->waitForStarts(2);
        $slow = self::role($roles, 'slow job');

        posix_kill($this->pid, SIGTERM);
        $this->waitFor(fn (): bool => str_contains($this->logged(), "asked slow job pid={$slow['pid']}") && str_contains($this->logged(), 'stopped work quick'));
        usleep(200_000);
        self::assertTrue($this->running(), 'it waits for the job in hand');
        self::assertTrue(self::alive($slow['pid']));

        posix_kill($this->pid, SIGTERM);

        self::assertSame(0, $this->waitForExit());
        self::assertFalse(self::alive($slow['pid']), 'the role is gone with it');
        self::assertCount(2, $this->started(), 'and nothing was started again');
    }

    /** Ctrl-C in a terminal: INT stops it as TERM does. */
    #[Test]
    public function an_int_stops_every_role_after_the_work_in_hand(): void
    {
        $this->supervise('supervisor', ['0 work a', '0 work b']);
        $roles = $this->waitForStarts(2);

        posix_kill($this->pid, SIGINT);

        self::assertSame(0, $this->waitForExit());
        foreach ($roles as $role) {
            self::assertStringContainsString("stopped {$role['command']} pid={$role['pid']}", $this->logged());
        }
        self::assertCount(2, $this->started());
    }

    /**
     * Out of processes or memory, fork fails. It is said, and tried again
     * later each time (1 s, then 2), not at once and not after the role's own
     * pause: asking faster makes it worse.
     */
    #[Test]
    public function a_fork_that_fails_is_reported_and_tried_again_with_a_growing_wait(): void
    {
        $this->supervise('supervisor', ['30 work a'], ['WK_FORK_FAIL' => '2']);
        $begun = microtime(true);
        $roles = $this->waitForStarts(1);

        self::assertGreaterThanOrEqual(2.5, microtime(true) - $begun, 'it waited 1 s and then 2 s');
        self::assertSame(
            "worker: work a could not be started (fork failed); again in 1s\nworker: work a could not be started (fork failed); again in 2s\n",
            $this->stderr(),
        );
        self::assertSame($this->pid, $roles[0]['ppid']);
    }

    /**
     * A forked role runs the code its parent loaded, so a role started again
     * no longer reads an edit or a deploy from disk, as a php of its own did.
     * HUP is how the worker is told to: every role finishes the work in hand,
     * and the process starts anew, with the same pid (the container does not
     * restart), command line and environment it was first given.
     */
    #[Test]
    public function a_hup_stops_every_role_and_starts_the_process_anew_to_load_the_code_again(): void
    {
        $this->supervise('command', ['5 work nats events', '5 work nats mail'], [], ['-d', 'memory_limit=300M']);
        $before = $this->waitForStarts(2);

        posix_kill($this->pid, SIGHUP);
        $this->waitFor(fn (): bool => count($this->started()) === 4);

        $logged = $this->logged();
        self::assertSame(
            ["loaded pid={$this->pid} mem=300M put=", "loaded pid={$this->pid} mem=300M put="],
            array_values(array_filter(explode("\n", $logged), static fn (string $line): bool => str_starts_with($line, 'loaded '))),
            'loaded twice by the same pid, with the php options it had, and without what the first boot put in its environment',
        );
        foreach ($before as $role) {
            self::assertStringContainsString("stopped {$role['command']} pid={$role['pid']}", $logged, 'it finished what it held first');
            self::assertLessThan(strrpos($logged, 'loaded pid='), strpos($logged, "stopped {$role['command']} pid={$role['pid']}"), 'before the code was loaded again');
        }
        $after = array_slice($this->started(), 2);
        self::assertCount(2, $after);
        $pidsBefore = array_column($before, 'pid');
        self::assertCount(2, $pidsBefore);
        self::assertSame(['work nats events', 'work nats mail'], self::sorted(array_column($after, 'command')));
        foreach ($after as $role) {
            self::assertSame($this->pid, $role['ppid']);
            self::assertNotContains($role['pid'], $pidsBefore);
        }
        self::assertStringContainsString("worker: HUP: every role finishes the work in hand, then the worker loads the code again\n", $this->stderr());

        posix_kill($this->pid, SIGTERM);
        self::assertSame(0, $this->waitForExit(), 'and it is still the worker: a TERM stops it');
    }

    /**
     * REGRESSION (review, core#256). A worker started with an empty
     * environment was started again with the two-argument pcntl_exec(), which
     * hands on the environment as it is now: what the boot had put there
     * (.env values) outlived the HUP. Empty is passed on as empty.
     */
    #[Test]
    public function a_hup_starts_a_process_that_had_no_environment_with_none_again(): void
    {
        $this->supervise('supervisor', ['5 work a'], [], [], emptyEnvironment: true);
        $this->waitForStarts(1);
        self::assertSame('', (string) file_get_contents("/proc/{$this->pid}/environ"), 'it was started with no environment at all');

        posix_kill($this->pid, SIGHUP);
        $this->waitFor(fn (): bool => substr_count($this->logged(), 'loaded pid=') === 2);

        self::assertSame(
            ["loaded pid={$this->pid} mem=", "loaded pid={$this->pid} mem="],
            array_map(
                static fn (string $line): string => (string) preg_replace('/mem=\S+ put=$/', 'mem=', $line),
                array_values(array_filter(explode("\n", $this->logged()), static fn (string $line): bool => str_starts_with($line, 'loaded '))),
            ),
            'the second start sees no WK_PUT either: put= is empty on both lines',
        );
    }

    /**
     * server:reload, then a deploy or docker stop a moment later. The HUP
     * already sent every role its TERM; the supervisor passed the TERM that
     * followed on as well, a role read it as the second one ("stop at once")
     * and died in the middle of its job, which ran again after its lease.
     * During the drain a TERM only means: stop, do not start again.
     */
    #[Test]
    public function a_term_during_a_hups_drain_lets_the_job_in_hand_finish_and_then_stops(): void
    {
        $this->supervise('supervisor', ['5 job a', '5 job b']);
        $roles = $this->waitForStarts(2);

        posix_kill($this->pid, SIGHUP);
        usleep(300_000);
        posix_kill($this->pid, SIGTERM);

        self::assertSame(0, $this->waitForExit());
        $logged = $this->logged();
        foreach ($roles as $role) {
            self::assertStringContainsString("stopped {$role['command']} pid={$role['pid']}", $logged, 'the job in hand ran to its end');
        }
        self::assertSame(1, substr_count($logged, 'loaded pid='), 'asked to stop: the code is not loaded again');
        self::assertCount(2, $this->started(), 'and no role is started again');
    }

    /**
     * Until a HUP or a restart, a role started again (it crashed, was killed,
     * or is scheduler:plan, every minute) runs the old code. Nothing showed
     * that: now the first such start after a change names the files, once.
     */
    #[Test]
    public function a_role_started_again_after_a_loaded_file_changed_says_which_and_how_to_load_it(): void
    {
        $watched = $this->log . '.watched.php';
        file_put_contents($watched, "<?php\n// one\n");
        touch($watched, time() - 60);
        $this->supervise('supervisor', ['0 work a'], ['WK_WATCH' => $watched]);
        $this->waitForStarts(1);

        posix_kill($this->started()[0]['pid'], SIGTERM);
        $this->waitFor(fn (): bool => count($this->started()) === 2);
        self::assertSame('', $this->stderr(), 'started again with nothing changed: nothing to say');

        file_put_contents($watched, "<?php\n// two, edited\n");
        posix_kill($this->started()[1]['pid'], SIGTERM);
        $this->waitFor(fn (): bool => count($this->started()) === 3);

        self::assertSame(
            "worker: 1 file(s) loaded when the worker started have changed since ({$watched}): work a and every other role started again still run the code loaded then. Send HUP to this process (pid {$this->pid}) or restart the worker to load them.\n",
            $this->stderr(),
        );

        posix_kill($this->started()[2]['pid'], SIGTERM);
        $this->waitFor(fn (): bool => count($this->started()) === 4);
        self::assertSame(1, substr_count($this->stderr(), 'have changed since'), 'the same change is said once, not at every start');
    }

    private static function alive(int $pid): bool
    {
        return @posix_kill($pid, 0);
    }

    /**
     * REGRESSION (review, core#256). The helper returned [] when /proc/<pid>/fd
     * could not be listed, and took a descriptor it could not read for "not a
     * socket": both "holds no socket" assertions passed with nothing looked at.
     */
    #[Test]
    public function the_socket_check_fails_when_it_cannot_look_at_the_process(): void
    {
        $gone = proc_open(['true'], [], $pipes);
        self::assertIsResource($gone);
        $pid = proc_get_status($gone)['pid'];
        proc_close($gone);

        try {
            self::sockets($pid);
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString("/proc/{$pid}/fd", $e->getMessage());

            return;
        }
        self::fail("sockets() answered for pid {$pid}, which does not exist: it looked at nothing");
    }

    /**
     * Fails when the descriptors cannot be listed, when none is found (the
     * standard three are always open), or when one cannot be read: an answer
     * of "no socket" has to come from having looked.
     *
     * @return list<string> what each open socket of $pid links to
     */
    private static function sockets(int $pid): array
    {
        $dir = "/proc/{$pid}/fd";
        $fds = @scandir($dir);
        self::assertIsArray($fds, "{$dir} cannot be listed: no such process, or not ours to read");
        $fds = array_values(array_diff($fds, ['.', '..']));
        self::assertNotSame([], $fds, "{$dir} lists no descriptor, and stdin, stdout and stderr are always open");

        $sockets = [];
        foreach ($fds as $fd) {
            $target = @readlink("{$dir}/{$fd}");
            self::assertIsString($target, "{$dir}/{$fd} cannot be read: it may be a socket");
            if (str_starts_with($target, 'socket:')) {
                $sockets[] = $target;
            }
        }

        return $sockets;
    }

    /**
     * @param list<string>          $lines
     * @param array<string, string> $env
     * @param list<string>          $phpOptions
     */
    private function supervise(string $mode, array $lines, array $env = [], array $phpOptions = [], bool $emptyEnvironment = false): void
    {
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $fixture = dirname(__DIR__, 2) . '/Fixtures/Worker/supervise.php';
        // exec: the pid proc_open gives is the supervisor's, not a shell's.
        // Before it, every descriptor this test process holds beyond the
        // standard three is closed (bash: sh cannot name one above 9): in the
        // full suite PHPUnit has connections open, and a child inherits them,
        // which is the very thing the supervisor reports.
        $closeInherited = 'for fd in /proc/$$/fd/*; do fd=${fd##*/}; [ "$fd" -gt 2 ] && eval "exec $fd>&-"; done; ';
        // env -i: bash gives its child PWD and SHLVL whatever it was given itself.
        $command = $closeInherited . 'exec ' . ($emptyEnvironment ? 'env -i ' : '')
            . implode(' ', array_map('escapeshellarg', [PHP_BINARY, ...$phpOptions, $fixture, $autoload, $this->log, $mode, ...$lines]));
        $process = proc_open(
            ['/bin/bash', '-c', $command],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', $this->log . '.err', 'a']],
            $pipes,
            null,
            [...getenv(), ...$env],
        );
        self::assertIsResource($process);
        $this->process = $process;
        $this->pid = proc_get_status($process)['pid'];
    }

    private function logged(): string
    {
        return is_file($this->log) ? (string) file_get_contents($this->log) : '';
    }

    private function stderr(): string
    {
        return (string) @file_get_contents($this->log . '.err');
    }

    /**
     * Every start of a role, in the order they happened.
     *
     * @return list<array{command: string, pid: int, ppid: int, sid: int}>
     */
    private function started(?string $command = null): array
    {
        $started = [];
        foreach (explode("\n", $this->logged()) as $line) {
            if (preg_match('/^(?!stopped |loaded |asked )(.+) pid=(\d+) ppid=(\d+) sid=(\d+)$/', $line, $m) !== 1) {
                continue;
            }
            if ($command === null || $m[1] === $command) {
                $started[] = ['command' => $m[1], 'pid' => (int) $m[2], 'ppid' => (int) $m[3], 'sid' => (int) $m[4]];
            }
        }

        return $started;
    }

    /** @return list<array{command: string, pid: int, ppid: int, sid: int}> */
    private function waitForStarts(int $count): array
    {
        $this->waitFor(fn (): bool => count($this->started()) >= $count);
        // Long enough for a start nobody asked for to show up too.
        usleep(200_000);
        $started = $this->started();
        self::assertCount($count, $started, $this->stderr());

        return $started;
    }

    /** @param \Closure(): bool $until */
    private function waitFor(\Closure $until): void
    {
        for ($i = 0; $i < 400 && !$until(); $i++) {
            usleep(25_000);
        }
        self::assertTrue($until(), "not reached in 10 s.\nlog:\n" . $this->logged() . "\nstderr:\n" . $this->stderr());
    }

    private function waitForExit(): int
    {
        self::assertIsResource($this->process);
        for ($i = 0; $i < 400; $i++) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                return $status['exitcode'];
            }
            usleep(25_000);
        }
        self::fail("the supervisor did not exit in 10 s.\nlog:\n" . $this->logged() . "\nstderr:\n" . $this->stderr());
    }

    /**
     * @param list<array{command: string, pid: int, ppid: int, sid: int}> $roles
     * @return array{command: string, pid: int, ppid: int, sid: int}
     */
    private static function role(array $roles, string $command): array
    {
        foreach ($roles as $role) {
            if ($role['command'] === $command) {
                return $role;
            }
        }
        self::fail("{$command} was not started");
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
