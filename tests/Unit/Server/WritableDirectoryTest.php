<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\ServerConfigurator;
use Semitexa\Core\Server\WritableDirectory;
use Semitexa\Core\Support\ProjectRoot;

/**
 * tk-root-owned-var-hangs-the-server: on 20.11 a prod server hung at every
 * start for 20 minutes with no log line, because var/log was root:root 775 and
 * the server, uid 1000, could not create that day's swoole.log.<date>.
 *
 * The suite runs as root, which no mode stops: the decision is tested from the
 * facts, and the real probe against a path no user can write, a file standing
 * where the directory should be.
 */
final class WritableDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-writable-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_writable_directory_is_no_problem(): void
    {
        self::assertNull(WritableDirectory::problem('/app/var/log', true, true, 1000, 1000, 0775, 1000, 1000, '/app/var'));
        self::assertNull(
            WritableDirectory::problem('/app/var/log', true, true, 0, 0, 0777, 1000, 1000, '/app/var'),
            'the start asks only whether it can write; whose the directory is, is the doctor\'s question',
        );
    }

    #[Test]
    public function the_incident_names_the_path_its_owner_and_mode_the_process_uid_and_the_fix(): void
    {
        $problem = WritableDirectory::problem('/app/var/log', true, false, 0, 0, 0775, 1000, 1001, '/app/var');

        self::assertSame(
            "[Semitexa] /app/var/log is not writable by this process (uid 1000).\n"
            . "[Semitexa]   owner 0:0, mode 0775\n"
            . "[Semitexa]   fix: chown -R 1000:1001 '/app/var' (as root), or start with bin/semitexa server:start, which reclaims ownership.",
            $problem,
        );
    }

    #[Test]
    public function a_directory_that_cannot_be_created_is_a_problem_too(): void
    {
        $problem = (string) WritableDirectory::problem('/app/var/log', false, false, 0, 0, 0755, 1000, 1000, '/app/var');

        self::assertStringContainsString('/app/var/log does not exist and this process (uid 1000) cannot create it.', $problem);
        self::assertStringContainsString('nearest existing parent: owner 0:0, mode 0755', $problem);
        self::assertStringContainsString("chown -R 1000:1000 '/app/var'", $problem);
    }

    #[Test]
    public function an_owner_that_cannot_be_read_is_shown_as_unknown(): void
    {
        self::assertStringContainsString(
            'owner ?:?, mode ?',
            (string) WritableDirectory::problem('/app/var/log', true, false, null, null, null, 1000, 1000, '/app/var'),
        );
    }

    #[Test]
    public function the_probe_writes_a_real_file_and_leaves_nothing_behind(): void
    {
        $facts = WritableDirectory::facts($this->root . '/var/log', true);

        self::assertTrue($facts['exists'], 'the start creates a missing log directory, as Swoole would not');
        self::assertTrue($facts['writable']);
        self::assertSame(WritableDirectory::processUid(), $facts['ownerUid']);
        self::assertSame([], array_values(array_diff((array) scandir($this->root . '/var/log'), ['.', '..'])));
        self::assertNull(WritableDirectory::inspect($this->root . '/var/log', $this->root));
    }

    /**
     * Review of core#253: a doctor run as root made the missing var/log as
     * root:root 0755, and the next start as the server's user could not write it.
     */
    #[Test]
    public function looking_creates_nothing_and_reports_the_parent_a_missing_directory_would_go_into(): void
    {
        $facts = WritableDirectory::facts($this->root . '/var/log/deep');

        self::assertDirectoryDoesNotExist($this->root . '/var/log');
        self::assertSame([], array_values(array_diff((array) scandir($this->root . '/var'), ['.', '..'])));
        self::assertFalse($facts['exists']);
        self::assertTrue($facts['writable'], 'the parent could take it');
        self::assertSame($this->root . '/var', $facts['described']);
        self::assertSame(fileperms($this->root . '/var') & 07777, $facts['mode']);
    }

    #[Test]
    public function only_the_start_creates_a_missing_directory(): void
    {
        self::assertNull(WritableDirectory::inspect($this->root . '/var/log', $this->root, true));
        self::assertDirectoryExists($this->root . '/var/log');

        self::assertSame(0, WritableDirectory::refusalExitCode([$this->root . '/var/run'], $this->stream(), $this->root));
        self::assertDirectoryExists($this->root . '/var/run');
    }

    /** An existing file is opened for append and closed: not truncated, and nothing made beside it. */
    #[Test]
    public function an_existing_file_is_probed_itself_and_left_as_it_was(): void
    {
        mkdir($this->root . '/var/log');
        file_put_contents($this->root . '/var/log/swoole.log', "a line\n");

        $facts = WritableDirectory::facts($this->root . '/var/log/swoole.log', true);

        self::assertTrue($facts['exists']);
        self::assertTrue($facts['writable']);
        self::assertSame($this->root . '/var/log/swoole.log', $facts['described']);
        self::assertSame("a line\n", file_get_contents($this->root . '/var/log/swoole.log'));
        self::assertSame(['swoole.log'], array_values(array_diff((array) scandir($this->root . '/var/log'), ['.', '..'])));
    }

    /**
     * Review of core#253: with rotation off Swoole opens the existing file and
     * never makes a sibling, so a writable file in a read-only directory is a
     * valid setup, and probing the directory refused it.
     */
    #[Test]
    public function without_rotation_an_existing_log_file_is_the_path_checked_not_its_directory(): void
    {
        mkdir($this->root . '/logs');
        $file = $this->root . '/logs/swoole.log';

        self::assertSame(
            $this->root . '/logs',
            WritableDirectory::startPaths(new ServerConfigurator($this->env($file, 'single')), '/app')[0],
            'no file yet: Swoole has to create it',
        );

        touch($file);
        self::assertSame($file, WritableDirectory::startPaths(new ServerConfigurator($this->env($file, 'single')), '/app')[0]);
        self::assertSame($file, WritableDirectory::startPaths(new ServerConfigurator($this->env($file, 'off')), '/app')[0]);
        foreach (['daily', 'hourly', 'monthly', 'every-minute'] as $rotation) {
            self::assertSame(
                $this->root . '/logs',
                WritableDirectory::startPaths(new ServerConfigurator($this->env($file, $rotation)), '/app')[0],
                $rotation . ': Swoole creates <log_file>.<date> beside it',
            );
        }
    }

    /** error.log by the same rule: PHP appends to an existing one. */
    #[Test]
    public function an_existing_error_log_is_checked_itself(): void
    {
        $config = new ServerConfigurator($this->env('/srv/logs/swoole.log'));
        self::assertSame($this->root . '/var/log', WritableDirectory::startPaths($config, $this->root)[1]);

        mkdir($this->root . '/var/log');
        touch($this->root . '/var/log/error.log');
        self::assertSame($this->root . '/var/log/error.log', WritableDirectory::startPaths($config, $this->root)[1]);
    }

    /** Review of core#253: 0555 owned by the server's own uid, and the advice was a chown to that uid. */
    #[Test]
    public function the_process_s_own_path_without_the_write_bit_is_a_chmod_not_a_chown(): void
    {
        $problem = (string) WritableDirectory::problem('/app/var/log', true, false, 1000, 1000, 0555, 1000, 1000, '/app/var');

        self::assertStringContainsString('owner 1000:1000, mode 0555', $problem);
        self::assertStringContainsString("fix: chmod u+w '/app/var/log': uid 1000 owns it, the mode forbids the write.", $problem);
        self::assertStringNotContainsString('chown', $problem);
    }

    #[Test]
    public function each_set_of_facts_gets_the_repair_that_changes_it(): void
    {
        $chown = "chown -R 1000:1000 '/app/var' (as root), or start with bin/semitexa server:start, which reclaims ownership.";
        $repair = static fn (?int $uid, ?int $gid, ?int $mode): string
            => WritableDirectory::repair($uid, $gid, $mode, 1000, 1000, '/app/var', '/app/var/log');

        self::assertSame($chown, $repair(0, 0, 0775), 'another owner, the mode is fine');
        self::assertSame($chown, $repair(null, null, null), 'an owner that cannot be read');
        self::assertSame(
            $chown . " Or, to write through group 1000: chmod ug+w '/app/var/log'.",
            $repair(0, 1000, 0755),
            'another owner, the process\'s group: the group is what would let it write',
        );
        self::assertSame($chown, $repair(0, 1000, 0775), 'the group already may: something else forbids, the chown stands');
        self::assertSame(
            $chown . " Then chmod u+w '/app/var/log': the mode forbids the owner the write.",
            $repair(0, 0, 0555),
            'both apply',
        );
        self::assertSame("chmod u+w '/app/var/log': uid 1000 owns it, the mode forbids the write.", $repair(1000, 1000, 0575));
        self::assertSame(
            'the owner and the mode allow the write, so look for a read-only mount or an ACL on /app/var/log.',
            $repair(1000, 1000, 0755),
        );
    }

    /** A directory that could not be made: the chmod goes to the parent that refused it. */
    #[Test]
    public function a_missing_directory_s_chmod_names_the_parent(): void
    {
        self::assertStringContainsString(
            "fix: chmod u+w '/app/var'",
            (string) WritableDirectory::problem('/app/var/log', false, false, 1000, 1000, 0555, 1000, 1000, '/app/var', '/app/var'),
        );
    }

    #[Test]
    public function a_file_in_the_directorys_place_is_reported_with_the_files_owner(): void
    {
        file_put_contents($this->root . '/var/log', '');
        chmod($this->root . '/var/log', 0640);

        $facts = WritableDirectory::facts($this->root . '/var/log/swoole', true);
        self::assertFalse($facts['exists']);
        self::assertFalse($facts['writable']);
        self::assertSame(0640, $facts['mode']);

        $problem = (string) WritableDirectory::inspect($this->root . '/var/log/swoole', $this->root, true);
        self::assertStringContainsString($this->root . '/var/log/swoole does not exist', $problem);
        self::assertTrue($facts['blocked']);
        self::assertStringContainsString('fix: move ' . escapeshellarg($this->root . '/var/log') . ' away: it is a file where a directory has to be.', $problem);
    }

    #[Test]
    public function the_fix_names_var_inside_the_project_and_the_directory_itself_outside_it(): void
    {
        self::assertSame('/app/var', WritableDirectory::chownTarget('/app/var/log', '/app'));
        self::assertSame('/app/var', WritableDirectory::chownTarget('/app/var', '/app/'));
        self::assertSame('/var/log/semitexa', WritableDirectory::chownTarget('/var/log/semitexa', '/app'));
        self::assertSame('/app/variable/log', WritableDirectory::chownTarget('/app/variable/log', '/app'));
    }

    /** The directory checked is the one Swoole is handed: the same resolution, not a second reading of the env. */
    #[Test]
    public function the_start_checks_the_directory_of_the_log_file_swoole_is_configured_with(): void
    {
        $relative = new ServerConfigurator($this->env('var/log/swoole.log'));
        self::assertSame(
            [ProjectRoot::get() . '/var/log', ProjectRoot::get() . '/var/run'],
            WritableDirectory::startPaths($relative),
        );
        self::assertSame($relative->logFilePath(), $relative->getServerOptions()['log_file']);
        self::assertSame(dirname($relative->logFilePath()), WritableDirectory::startPaths($relative)[0]);

        self::assertSame(
            ['/srv/logs', '/app/var/log', '/app/var/run'],
            WritableDirectory::startPaths(new ServerConfigurator($this->env('/srv/logs/swoole.log')), '/app'),
        );
    }

    #[Test]
    public function the_start_goes_on_silently_when_every_directory_can_be_written(): void
    {
        $stderr = $this->stream();

        self::assertSame(0, WritableDirectory::refusalExitCode([$this->root . '/var/log', $this->root . '/var/run'], $stderr, $this->root));
        self::assertSame('', $this->written($stderr));
    }

    #[Test]
    public function the_start_is_refused_with_a_non_zero_exit_and_a_block_naming_each_directory(): void
    {
        file_put_contents($this->root . '/var/log', '');
        $stderr = $this->stream();

        $code = WritableDirectory::refusalExitCode(
            [$this->root . '/var/log/a', $this->root . '/var/run', $this->root . '/var/log/b'],
            $stderr,
            $this->root,
        );

        self::assertSame(1, $code);
        $written = $this->written($stderr);
        self::assertStringContainsString('THE SERVER WILL NOT START', $written);
        self::assertStringContainsString($this->root . '/var/log/a does not exist', $written);
        self::assertStringContainsString($this->root . '/var/log/b does not exist', $written);
        self::assertStringNotContainsString('/var/run', $written, 'a writable directory is not named');
        self::assertStringContainsString('fix: move ' . escapeshellarg($this->root . '/var/log') . ' away', $written);
        foreach (explode("\n", rtrim($written, "\n")) as $line) {
            self::assertStringStartsWith('[Semitexa] ', $line, 'every line is findable in a shared log');
        }
    }

    /** @return resource */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    /** @param resource $stream */
    private function written($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function env(string $logFile, string $rotation = 'daily'): Environment
    {
        return new Environment(
            appEnv: 'test',
            appDebug: false,
            appName: 'Semitexa',
            appHost: 'localhost',
            appPort: 8000,
            swoolePort: 9502,
            swooleSsePort: 9503,
            swooleHost: '0.0.0.0',
            swooleWorkerNum: 1,
            swooleMaxRequest: 1,
            swooleMaxCoroutine: 1,
            swooleLogFile: $logFile,
            swooleLogLevel: 1,
            swooleSessionTableSize: 1,
            swooleSessionMaxBytes: 1,
            swooleSseWorkerTableSize: 1,
            swooleSseDeliverTableSize: 1,
            swooleSsePayloadMaxBytes: 1,
            corsAllowOrigin: '*',
            corsAllowMethods: 'GET',
            corsAllowHeaders: 'Content-Type',
            corsAllowCredentials: false,
            swooleLogRotation: $rotation,
            swooleLogRetentionDays: 14,
        );
    }
}
