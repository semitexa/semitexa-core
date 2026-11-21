<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use Composer\Autoload\ClassLoader;
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

    /** @param list<string> $lines */
    private function supervise(string $mode, array $lines): void
    {
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $fixture = dirname(__DIR__, 2) . '/Fixtures/Worker/supervise.php';
        // exec: the pid proc_open gives is the supervisor's, not a shell's.
        $command = 'exec ' . implode(' ', array_map('escapeshellarg', [PHP_BINARY, $fixture, $autoload, $this->log, $mode, ...$lines]));
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', $this->log . '.err', 'w']], $pipes);
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
            if (preg_match('/^(?!stopped |loaded )(.+) pid=(\d+) ppid=(\d+) sid=(\d+)$/', $line, $m) !== 1) {
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
