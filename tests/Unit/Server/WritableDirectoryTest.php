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
        $facts = WritableDirectory::facts($this->root . '/var/log');

        self::assertTrue($facts['exists'], 'a missing log directory is created, as Swoole would not');
        self::assertTrue($facts['writable']);
        self::assertSame(WritableDirectory::processUid(), $facts['ownerUid']);
        self::assertSame([], array_values(array_diff((array) scandir($this->root . '/var/log'), ['.', '..'])));
        self::assertNull(WritableDirectory::inspect($this->root . '/var/log', $this->root));
    }

    #[Test]
    public function a_file_in_the_directorys_place_is_reported_with_the_files_owner(): void
    {
        file_put_contents($this->root . '/var/log', '');
        chmod($this->root . '/var/log', 0640);

        $facts = WritableDirectory::facts($this->root . '/var/log/swoole');
        self::assertFalse($facts['exists']);
        self::assertFalse($facts['writable']);
        self::assertSame(0640, $facts['mode']);

        $problem = (string) WritableDirectory::inspect($this->root . '/var/log/swoole', $this->root);
        self::assertStringContainsString($this->root . '/var/log/swoole does not exist', $problem);
        self::assertStringContainsString('chown -R ' . WritableDirectory::processUid() . ':' . WritableDirectory::processGid() . ' ' . escapeshellarg($this->root . '/var'), $problem);
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
            WritableDirectory::startDirectories($relative),
        );
        self::assertSame($relative->logFilePath(), $relative->getServerOptions()['log_file']);
        self::assertSame(dirname($relative->logFilePath()), WritableDirectory::startDirectories($relative)[0]);

        self::assertSame(
            ['/srv/logs', '/app/var/log', '/app/var/run'],
            WritableDirectory::startDirectories(new ServerConfigurator($this->env('/srv/logs/swoole.log')), '/app'),
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
        self::assertStringContainsString('bin/semitexa server:start', $written);
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

    private function env(string $logFile): Environment
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
            swooleLogRotation: 'daily',
            swooleLogRetentionDays: 14,
        );
    }
}
