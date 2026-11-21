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

        self::assertSame(0, WritableDirectory::refusalExitCode([$this->root . '/var/run' => null], $this->stream(), $this->root));
        self::assertDirectoryExists($this->root . '/var/run');
    }

    /** An existing file is opened for append and closed: not truncated, and nothing made beside it. */
    #[Test]
    public function an_existing_file_is_probed_itself_and_left_as_it_was(): void
    {
        mkdir($this->root . '/var/log');
        file_put_contents($this->root . '/var/log/swoole.log', "a line\n");

        $facts = WritableDirectory::facts($this->root . '/var/log/swoole.log', true, 'a+');

        self::assertTrue($facts['exists']);
        self::assertTrue($facts['writable']);
        self::assertFalse($facts['blocked']);
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
            array_key_first(WritableDirectory::startPaths(new ServerConfigurator($this->env($file, 'single')), '/app')),
            'no file yet: Swoole has to create it',
        );

        touch($file);
        self::assertSame(
            [$file => 'a+', '/app/var/log' => null, '/app/var/run' => null],
            WritableDirectory::startPaths(new ServerConfigurator($this->env($file, 'single')), '/app'),
            'the file is the one entry opened for append; the rest are directories',
        );
        self::assertSame($file, array_key_first(WritableDirectory::startPaths(new ServerConfigurator($this->env($file, 'single')), '/app')));
        self::assertSame($file, array_key_first(WritableDirectory::startPaths(new ServerConfigurator($this->env($file, 'off')), '/app')));
        foreach (['daily', 'hourly', 'monthly', 'every-minute'] as $rotation) {
            self::assertSame(
                $this->root . '/logs',
                array_key_first(WritableDirectory::startPaths(new ServerConfigurator($this->env($file, $rotation)), '/app')),
                $rotation . ': Swoole creates <log_file>.<date> beside it',
            );
        }
    }

    /**
     * Review of core#253: Swoole opens its log O_RDWR|O_APPEND|O_CREAT (6.2
     * src/core/log.cc; on 6.2.5 a 0200 log gives "Logger::open() failed.
     * Permission denied"). The append-only probe said yes to that file.
     * error.log is PHP's, opened for append alone, so 0200 is enough for it.
     */
    #[Test]
    public function a_swoole_log_has_to_open_for_reading_too_and_error_log_only_for_append(): void
    {
        mkdir($this->root . '/var/log');
        $log = $this->root . '/var/log/swoole.log';
        $errorLog = $this->root . '/var/log/error.log';
        file_put_contents($log, "kept\n");
        file_put_contents($errorLog, "kept\n");
        chmod($log, 0200);
        chmod($errorLog, 0200);
        $paths = WritableDirectory::startPaths(new ServerConfigurator($this->env($log, 'single')), $this->root);
        self::assertSame([$log => 'a+', $errorLog => 'a', $this->root . '/var/run' => null], $paths);
        $uid = WritableDirectory::processUid();
        $stderr = $this->stream();

        $code = WritableDirectory::refusalExitCode($paths, $stderr, $this->root);

        if ($uid === 0) {
            self::assertSame(0, $code, 'no mode stops root, Swoole run as root included');

            return;
        }
        self::assertSame(1, $code);
        self::assertSame(
            "[Semitexa] ============================================================\n"
            . "[Semitexa] THE SERVER WILL NOT START: it cannot write where it logs.\n"
            . "[Semitexa] Started anyway, every worker would block on the log file\n"
            . "[Semitexa] and the server would hang without a single log line.\n"
            . "[Semitexa] {$log} is not writable by this process (uid {$uid}).\n"
            . "[Semitexa]   owner {$uid}:" . filegroup($log) . ", mode 0200\n"
            . '[Semitexa]   fix: chmod u+r ' . escapeshellarg($log) . ": uid {$uid} owns it, and the mode forbids the read Swoole opens its log with.\n"
            . "[Semitexa] ============================================================\n",
            $this->written($stderr),
            'the Swoole log alone is named: error.log, as write-only, is fine',
        );

        // Read and write for its owner is all Swoole needs, in a directory nobody may write.
        chmod($log, 0600);
        chmod($this->root . '/var/log', 0555);
        try {
            self::assertSame(['exists' => true, 'writable' => true, 'blocked' => false], array_intersect_key(
                WritableDirectory::facts($log, true, 'a+'),
                ['exists' => 1, 'writable' => 1, 'blocked' => 1],
            ));
            self::assertSame(0, WritableDirectory::refusalExitCode([$log => 'a+', $errorLog => 'a'], $this->stream(), $this->root));
        } finally {
            chmod($this->root . '/var/log', 0755);
        }
        self::assertSame("kept\n", file_get_contents($log), 'opened and closed, nothing written');
    }

    /** error.log by the same rule: PHP appends to an existing one. */
    #[Test]
    public function an_existing_error_log_is_checked_itself(): void
    {
        $config = new ServerConfigurator($this->env('/srv/logs/swoole.log'));
        self::assertSame(
            ['/srv/logs' => null, $this->root . '/var/log' => null, $this->root . '/var/run' => null],
            WritableDirectory::startPaths($config, $this->root),
        );

        mkdir($this->root . '/var/log');
        touch($this->root . '/var/log/error.log');
        self::assertSame(
            ['/srv/logs' => null, $this->root . '/var/log/error.log' => 'a', $this->root . '/var/run' => null],
            WritableDirectory::startPaths($config, $this->root),
        );
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

        $forSwooleLog = static fn (int $uid, int $mode): string
            => WritableDirectory::repair($uid, $uid, $mode, 1000, 1000, '/app/var', '/app/var/log/swoole.log', false, true);
        self::assertSame(
            "chmod u+r '/app/var/log/swoole.log': uid 1000 owns it, and the mode forbids the read Swoole opens its log with.",
            $forSwooleLog(1000, 0200),
        );
        self::assertSame(
            $chown . " Then chmod u+r '/app/var/log/swoole.log': the mode forbids the owner the read Swoole opens its log with.",
            $forSwooleLog(0, 0200),
        );
        self::assertSame(
            "chmod u+w '/app/var/log/swoole.log': uid 1000 owns it, the mode forbids the write.",
            $forSwooleLog(1000, 0400),
        );
        self::assertSame(
            'the owner and the mode allow the write, so look for a read-only mount or an ACL on /app/var/log/swoole.log.',
            $forSwooleLog(1000, 0600),
        );
        self::assertSame(
            'the owner and the mode allow the write, so look for a read-only mount or an ACL on /app/var/log.',
            $repair(1000, 1000, 0300),
            'a directory, or error.log, needs no read bit',
        );
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
        // A relative log file resolves as Swoole is handed it. The root passed in is this test's
        // own: the real project's var/log may hold an error.log, which is an entry of its own.
        $relative = new ServerConfigurator($this->env('var/log/swoole.log'));
        self::assertSame(ProjectRoot::get() . '/var/log/swoole.log', $relative->logFilePath());
        self::assertSame($relative->logFilePath(), $relative->getServerOptions()['log_file']);
        self::assertSame(
            [ProjectRoot::get() . '/var/log' => null, $this->root . '/var/log' => null, $this->root . '/var/run' => null],
            WritableDirectory::startPaths($relative, $this->root),
        );

        self::assertSame(
            ['/srv/logs' => null, '/app/var/log' => null, '/app/var/run' => null],
            WritableDirectory::startPaths(new ServerConfigurator($this->env('/srv/logs/swoole.log')), '/app'),
        );
        self::assertSame(
            [$this->root . '/var/log' => null, $this->root . '/var/run' => null],
            WritableDirectory::startPaths(new ServerConfigurator($this->env($this->root . '/var/log/swoole.log')), $this->root),
            'the log directory and var/log are one entry when they are one path',
        );
    }

    /**
     * Review of core#253: the parent of a rotated log has to be a directory. A
     * writable regular file there passed the append probe, and Swoole could not
     * create swoole.log.<date> inside it.
     */
    #[Test]
    public function a_writable_file_where_a_directory_is_required_is_refused(): void
    {
        $logs = $this->root . '/logs';
        file_put_contents($logs, "kept\n");
        chmod($logs, 0664);
        $paths = WritableDirectory::startPaths(new ServerConfigurator($this->env($logs . '/swoole.log')), $this->root);
        self::assertSame([$logs => null, $this->root . '/var/log' => null, $this->root . '/var/run' => null], $paths);

        self::assertSame(
            [
                'exists' => true,
                'writable' => false,
                'ownerUid' => WritableDirectory::processUid(),
                'ownerGid' => filegroup($logs),
                'mode' => 0664,
                'described' => $logs,
                'blocked' => true,
                'special' => false,
            ],
            WritableDirectory::facts($logs, true),
        );

        $stderr = $this->stream();
        self::assertSame(1, WritableDirectory::refusalExitCode($paths, $stderr, $this->root));
        self::assertStringContainsString(
            "[Semitexa] {$logs} is not writable by this process (uid " . WritableDirectory::processUid() . ").\n"
            . '[Semitexa]   owner ' . WritableDirectory::processUid() . ':' . filegroup($logs) . ", mode 0664\n"
            . '[Semitexa]   fix: move ' . escapeshellarg($logs) . " away: it is a file where a directory has to be.\n",
            $this->written($stderr),
        );
        self::assertSame("kept\n", file_get_contents($logs), 'looked at, never written');
    }

    /**
     * Review of core#253: opening a FIFO nobody reads for append blocks for
     * good, so the check that exists to prevent a silent hang would be one.
     * Run in a child with a time limit: without the guard this is exit 124.
     * As the Swoole log it is judged by access(2), with no open at all
     * (tk-swoole-log-to-stdout-refused-at-start, review of core#254).
     */
    #[Test]
    public function a_fifo_never_blocks_the_probe_whichever_kind_of_path_it_stands_for(): void
    {
        $fifo = $this->root . '/var/run';
        self::assertTrue(posix_mkfifo($fifo, 0600));
        $class = (string) (new \ReflectionClass(WritableDirectory::class))->getFileName();
        $code = 'require $argv[1]; echo json_encode(array_map('
            . 'static fn (?string $open): array => array_intersect_key('
            . '\\' . WritableDirectory::class . '::facts($argv[2], false, $open), ["exists" => 1, "writable" => 1, "blocked" => 1]), '
            . '["directory" => null, "file" => "a", "swoole log" => "a+"]));';

        exec('timeout 10 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($class) . ' ' . escapeshellarg($fifo) . ' 2>&1', $output, $exit);

        self::assertSame(0, $exit, 'the probe returned instead of blocking on the FIFO: ' . implode("\n", $output));
        self::assertSame(
            '{"directory":{"exists":true,"writable":false,"blocked":true},"file":{"exists":true,"writable":false,"blocked":false},'
            . '"swoole log":{"exists":true,"writable":true,"blocked":false}}',
            implode("\n", $output),
        );
    }

    /**
     * tk-swoole-log-to-stdout-refused-at-start: SWOOLE_LOG_FILE=/dev/stdout is
     * how a container logs. is_file() is false for it, so the start probed
     * /dev, which uid 1000 cannot write, and refused. The child's stdout is a
     * pipe, as a container's is: the case PHP's own fopen() cannot open.
     */
    #[Test]
    public function the_stdout_of_a_container_is_probed_as_itself_and_accepted(): void
    {
        foreach (['/dev/stdout', '/dev/stderr'] as $device) {
            self::assertSame(
                [$device => 'a+', '/app/var/log' => null, '/app/var/run' => null],
                WritableDirectory::startPaths(new ServerConfigurator($this->env($device, 'single')), '/app'),
                $device . ' is the path, never /dev',
            );
        }
        mkdir($this->root . '/var/run');
        $class = (string) (new \ReflectionClass(WritableDirectory::class))->getFileName();
        $code = 'require $argv[1]; echo "exit ", \\' . WritableDirectory::class . '::refusalExitCode('
            . '["/dev/stdout" => "a+", "/dev/stderr" => "a+", $argv[2] => null], STDERR, "/app");';

        exec('timeout 10 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($class) . ' ' . escapeshellarg($this->root . '/var/run') . ' 2>&1', $output, $exit);

        self::assertSame('exit 0', implode("\n", $output), 'a pipe on stdout and stderr opens, and nothing is said');
        self::assertSame(0, $exit);
    }

    #[Test]
    public function a_fifo_at_the_log_path_is_probed_as_itself(): void
    {
        mkdir($this->root . '/logs');
        $fifo = $this->root . '/logs/swoole.log';
        self::assertTrue(posix_mkfifo($fifo, 0600));
        self::assertTrue(WritableDirectory::isSpecial($fifo));

        self::assertSame(
            [$fifo => 'a+', '/app/var/log' => null, '/app/var/run' => null],
            WritableDirectory::startPaths(new ServerConfigurator($this->env($fifo, 'single')), '/app'),
        );
        $facts = WritableDirectory::facts($fifo, false, 'a+');
        self::assertTrue($facts['special']);
        self::assertTrue($facts['writable'], 'readable and writable, with no reader on the other end');
        self::assertTrue(WritableDirectory::isNamedFifo($fifo));
        self::assertFalse(WritableDirectory::isNamedFifo('/dev/null'));
        self::assertFalse(WritableDirectory::isNamedFifo($this->root . '/logs'));
        self::assertFalse($facts['blocked']);
        self::assertSame($fifo, $facts['described']);
        self::assertNull(WritableDirectory::inspect($fifo, '/app', true, 'a+'));
        clearstatcache();
        self::assertSame('fifo', filetype($fifo), 'still a FIFO, nothing created over it');
    }

    /**
     * Review of core#254: a collector that reads the FIFO once (`cat fifo`)
     * waits in open() for the first writer. A probe that opens the FIFO is
     * that writer, and its close is the end of file: the collector leaves
     * before Swoole opens the log, and the log then fills the pipe and
     * stalls the workers. So the probe never opens it. The reader here is
     * released at the end by a real open, which also proves it was attached.
     */
    #[Test]
    public function a_fifo_with_a_collector_attached_is_not_opened_so_the_collector_sees_no_end_of_file(): void
    {
        $fifo = $this->root . '/var/swoole.fifo';
        self::assertTrue(posix_mkfifo($fifo, 0600));
        $null = static fn (string $mode): array => ['file', '/dev/null', $mode];
        $collector = proc_open(['cat', $fifo], [$null('r'), $null('w'), $null('w')], $pipes);
        self::assertIsResource($collector);
        $reading = static fn (): bool => proc_get_status($collector)['running'];
        try {
            usleep(300_000); // the collector is in open(), waiting for a writer
            $stderr = $this->stream();

            self::assertTrue(WritableDirectory::facts($fifo, false, 'a+')['writable']);
            self::assertSame(0, WritableDirectory::refusalExitCode([$fifo => 'a+'], $stderr, $this->root));
            usleep(500_000);
            $leftAfterTheProbe = !$reading();

            $writer = fopen($fifo, 'r+');
            self::assertIsResource($writer);
            fclose($writer);
            for ($i = 0; $i < 50 && $reading(); $i++) {
                usleep(100_000);
            }
            self::assertFalse($reading(), 'the collector was attached: a real open and close ends it');
            self::assertFalse($leftAfterTheProbe, 'the probe opened the FIFO and its close sent the collector the end of file');
        } finally {
            // Whatever failed above, no collector is left blocked on a FIFO that tearDown removes.
            if ($reading()) {
                proc_terminate($collector, 9);
            }
            proc_close($collector);
        }
    }

    #[Test]
    public function a_fifo_this_process_may_not_write_is_still_refused_by_its_own_name(): void
    {
        $root = WritableDirectory::processUid() === 0; // no mode stops root: for it the same FIFO is accepted
        $fifo = $this->root . '/var/swoole.fifo';
        self::assertTrue(posix_mkfifo($fifo, 0600));
        foreach ([0400, 0200, 0000] as $mode) {
            chmod($fifo, $mode);
            $stderr = $this->stream();

            self::assertSame($root ? 0 : 1, WritableDirectory::refusalExitCode([$fifo => 'a+'], $stderr, $this->root), sprintf('%04o', $mode));
            if ($root) {
                self::assertSame('', $this->written($stderr));
            } else {
                self::assertStringContainsString("[Semitexa] {$fifo} cannot be opened for reading and writing by this process", $this->written($stderr));
            }
        }
    }

    /**
     * A socket file opens for nobody, root included (ENXIO), so this holds
     * under any uid.
     */
    #[Test]
    public function a_special_file_that_does_not_open_is_refused_by_its_own_name(): void
    {
        $socket = $this->root . '/var/log.sock';
        $server = stream_socket_server('unix://' . $socket);
        self::assertIsResource($server);
        self::assertSame([$socket => 'a+'], array_slice(WritableDirectory::startPaths(new ServerConfigurator($this->env($socket, 'single')), '/app'), 0, 1, true));
        $stderr = $this->stream();

        $exit = WritableDirectory::refusalExitCode([$socket => 'a+'], $stderr, $this->root);
        fclose($server);

        $written = $this->written($stderr);
        self::assertSame(1, $exit);
        self::assertStringContainsString("[Semitexa] {$socket} cannot be opened for reading and writing by this process (uid " . WritableDirectory::processUid() . ').', $written);
        self::assertStringContainsString('fix: point SWOOLE_LOG_FILE at a path', $written);
        self::assertStringContainsString($socket . ' is not a regular file', $written);
        self::assertStringNotContainsString('chown -R', $written, 'nobody chowns a device or a pipe');
        self::assertStringNotContainsString($this->root . "/var is not writable", $written, 'its directory is not the question');
    }

    /**
     * With rotation on Swoole creates <log_file>.<date> beside the path and
     * never opens the path: as uid 1000 with /dev/stdout it hung (6.2.5), as
     * root the log went to a file in /dev instead of stdout. So it is refused
     * whenever the path is special, here in a directory anyone can write.
     */
    #[Test]
    public function with_rotation_a_special_log_path_is_refused_even_in_a_writable_directory(): void
    {
        mkdir($this->root . '/logs');
        mkdir($this->root . '/var/log');
        mkdir($this->root . '/var/run');
        $fifo = $this->root . '/logs/swoole.log';
        self::assertTrue(posix_mkfifo($fifo, 0600));

        foreach (['daily', 'hourly', 'monthly', 'every-minute'] as $rotation) {
            $config = new ServerConfigurator($this->env($fifo, $rotation));
            $paths = WritableDirectory::startPaths($config, $this->root);
            self::assertSame([$this->root . '/var/log', $this->root . '/var/run'], array_keys($paths), 'its directory is not the question');
            $stderr = $this->stream();

            self::assertSame(1, WritableDirectory::refusalExitCode($paths, $stderr, $this->root, WritableDirectory::rotationProblem($config)), $rotation);

            $written = $this->written($stderr);
            self::assertStringContainsString('THE SERVER WILL NOT START', $written);
            self::assertStringContainsString("[Semitexa] rotation needs a regular file: {$fifo} is a device, a FIFO or a link to a descriptor", $written);
            self::assertStringContainsString('SWOOLE_LOG_ROTATION=' . $rotation . ' Swoole creates ' . $fifo . '.<date>', $written);
            self::assertStringContainsString("[Semitexa]   fix: set SWOOLE_LOG_ROTATION=single to log to {$fifo}", $written);
            self::assertStringNotContainsString('chown', $written);
        }

        $devices = WritableDirectory::rotationProblem(new ServerConfigurator($this->env('/dev/stdout', 'daily')));
        self::assertNotNull($devices);
        self::assertStringContainsString('/dev/stdout.<date>', $devices['message']);
        self::assertSame(['/app/var/log', '/app/var/run'], array_keys(WritableDirectory::startPaths(new ServerConfigurator($this->env('/dev/stdout', 'daily')), '/app')), '/dev is never probed');
        self::assertNull(WritableDirectory::rotationProblem(new ServerConfigurator($this->env('/dev/stdout', 'single'))));
        self::assertNull(WritableDirectory::rotationProblem(new ServerConfigurator($this->env($fifo, 'single'))));
        self::assertNull(WritableDirectory::rotationProblem(new ServerConfigurator($this->env($this->root . '/logs/missing.log', 'daily'))));
    }

    /**
     * The probe's shell says nothing: the exit code is the answer. A refused
     * open still refuses, and for /dev/stderr it is that stderr that is
     * opened, not the /dev/null the shell's own words go to.
     */
    #[Test]
    public function the_probe_prints_nothing_and_a_refused_open_still_refuses(): void
    {
        $socket = $this->root . '/var/log.sock';
        $server = stream_socket_server('unix://' . $socket);
        self::assertIsResource($server);
        $writeOnly = $this->root . '/var/stderr.log';
        touch($writeOnly);
        chmod($writeOnly, 0200);
        $class = (string) (new \ReflectionClass(WritableDirectory::class))->getFileName();
        $code = 'require $argv[1]; echo json_encode(\\' . WritableDirectory::class . '::opensReadWrite($argv[2]));';
        $run = static function (string $path, string $stderr) use ($code, $class): string {
            exec('timeout 10 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($class) . ' ' . escapeshellarg($path) . ' ' . $stderr, $output);

            return implode("\n", $output);
        };

        self::assertSame('false', $run($socket, '2>&1'), 'refused, and not a word beside the answer');
        self::assertSame('true', $run('/dev/stderr', '2>&1'), 'a pipe on stderr opens');
        self::assertSame('true', $run('/dev/null', '2>&1'));
        // stderr is a file opened for write alone, mode 0200: the read and write reopen is refused (root opens anything).
        self::assertSame(WritableDirectory::processUid() === 0 ? 'true' : 'false', $run('/dev/stderr', '2>>' . escapeshellarg($writeOnly)));
        self::assertSame(WritableDirectory::processUid() === 0 ? 'true' : 'false', $run($writeOnly, '2>>' . escapeshellarg($writeOnly)), 'the same file by its own name');
        fclose($server);
        chmod($writeOnly, 0600);
        self::assertSame('', file_get_contents($writeOnly), 'nothing was written to the stderr under test');
    }

    #[Test]
    public function the_default_regular_log_file_is_judged_as_before(): void
    {
        mkdir($this->root . '/var/log');
        $file = $this->root . '/var/log/swoole.log';
        $single = new ServerConfigurator($this->env($file, 'single'));
        $daily = new ServerConfigurator($this->env($file, 'daily'));

        self::assertSame($this->root . '/var/log', array_key_first(WritableDirectory::startPaths($single, '/app')), 'missing: its directory');
        self::assertFalse(WritableDirectory::isSpecial($file), 'a missing path is not special');
        touch($file);
        self::assertFalse(WritableDirectory::isSpecial($file));
        self::assertFalse(WritableDirectory::isSpecial(dirname($file)));
        self::assertSame($file, array_key_first(WritableDirectory::startPaths($single, '/app')));
        self::assertSame($this->root . '/var/log', array_key_first(WritableDirectory::startPaths($daily, '/app')));
        self::assertNull(WritableDirectory::rotationProblem($daily));
        self::assertSame(
            ['exists' => true, 'writable' => true, 'described' => $file, 'blocked' => false, 'special' => false],
            array_intersect_key(WritableDirectory::facts($file, false, 'a+'), ['exists' => 1, 'writable' => 1, 'blocked' => 1, 'special' => 1, 'described' => 1]),
        );
        self::assertFalse(WritableDirectory::facts($this->root . '/var/log', false, null)['special']);
        // A directory at the log path is never opened as a file, single rotation or not.
        self::assertSame($this->root . '/var', array_key_first(WritableDirectory::startPaths(new ServerConfigurator($this->env($this->root . '/var/log', 'single')), '/app')));
    }

    #[Test]
    public function the_start_goes_on_silently_when_every_directory_can_be_written(): void
    {
        $stderr = $this->stream();

        self::assertSame(0, WritableDirectory::refusalExitCode([$this->root . '/var/log' => null, $this->root . '/var/run' => null], $stderr, $this->root));
        self::assertSame('', $this->written($stderr));
    }

    #[Test]
    public function the_start_is_refused_with_a_non_zero_exit_and_a_block_naming_each_directory(): void
    {
        file_put_contents($this->root . '/var/log', '');
        $stderr = $this->stream();

        $code = WritableDirectory::refusalExitCode(
            [$this->root . '/var/log/a' => null, $this->root . '/var/run' => null, $this->root . '/var/log/b' => null],
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
