<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use Semitexa\Core\Tests\Support\StaticState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\AsyncJsonLogger;
use Semitexa\Core\Support\ProjectRoot;

final class AsyncJsonLoggerTest extends TestCase
{
    private ?string $previousLogFile = null;
    private ?string $previousLogLevel = null;
    private ?string $previousLogMaxBytes = null;

    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $projectRootState;

    private ?string $previousLogSink = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The tests reset ProjectRoot to resolve a log path; put back the root
        // an earlier test (or the suite) had cached.
        $this->projectRootState = StaticState::snapshot(ProjectRoot::class);

        $this->previousLogFile = getenv('LOG_FILE') !== false ? (string) getenv('LOG_FILE') : null;
        $this->previousLogLevel = getenv('LOG_LEVEL') !== false ? (string) getenv('LOG_LEVEL') : null;
        $this->previousLogMaxBytes = getenv('LOG_MAX_BYTES') !== false ? (string) getenv('LOG_MAX_BYTES') : null;
        $this->previousLogSink = getenv('LOG_SINK') !== false ? (string) getenv('LOG_SINK') : null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('LOG_FILE', $this->previousLogFile);
        $this->restoreEnv('LOG_LEVEL', $this->previousLogLevel);
        // The rotation test sets LOG_MAX_BYTES and used to clear it outright, so
        // a value the suite arrived with was wiped for every later test in the
        // process. putenv() is process-wide; a test that unsets what it did not
        // set poisons whatever runs after it.
        $this->restoreEnv('LOG_MAX_BYTES', $this->previousLogMaxBytes);
        $this->restoreEnv('LOG_SINK', $this->previousLogSink);
        StaticState::restore($this->projectRootState);

        parent::tearDown();
    }

    /**
     * One write per batch: past the 4 KiB a pipe takes whole, another
     * worker's write could land mid-line in the container's log.
     */
    #[Test]
    public function stderr_is_written_in_pipe_sized_runs_of_whole_lines(): void
    {
        $chunks = new \ReflectionMethod(AsyncJsonLogger::class, 'pipeSizedChunks');
        $line = str_repeat('x', 999) . "\n";
        $batch = str_repeat($line, 10) . str_repeat('y', 5000) . "\n" . $line;

        /** @var list<string> $written */
        $written = $chunks->invoke(null, $batch);

        self::assertSame($batch, implode('', $written), 'nothing lost or reordered');
        foreach ($written as $chunk) {
            self::assertStringEndsWith("\n", $chunk, 'never a line cut in two');
            self::assertTrue(strlen($chunk) <= 4096 || substr_count($chunk, "\n") === 1, 'a run fits a pipe write, or is one long line alone');
        }
        self::assertSame([str_repeat('a', 10) . "\n"], $chunks->invoke(null, str_repeat('a', 10) . "\n"), 'a short batch is one write');
    }

    #[Test]
    public function flush_falls_back_when_context_cannot_be_json_encoded(): void
    {
        ProjectRoot::reset();
        $relativePath = 'var/tmp/async-json-logger-test-' . bin2hex(random_bytes(6)) . '.log';
        $absolutePath = ProjectRoot::get() . '/' . $relativePath;

        putenv('LOG_FILE=' . $relativePath);
        putenv('LOG_LEVEL=debug');

        $logger = new AsyncJsonLogger();
        $this->injectEnvironment($logger);

        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            $logger->error('problematic entry', ['stream' => $resource]);
        } finally {
            fclose($resource);
        }

        self::assertFileExists($absolutePath);

        $lines = file($absolutePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('error', $entry['level']);
        self::assertSame('Log entry could not be JSON encoded', $entry['message']);
        self::assertSame('problematic entry', $entry['context']['original_message']);
        self::assertNotEmpty($entry['context']['encoding_error']);

        @unlink($absolutePath);
    }

    /**
     * One invalid UTF-8 byte (a Latin-1 name, a raw path) used to discard the
     * whole entry; in the message it emptied even the fallback line.
     */
    #[Test]
    public function invalid_utf8_in_message_or_context_is_substituted_not_dropped(): void
    {
        ProjectRoot::reset();
        $relativePath = 'var/tmp/async-json-logger-utf8-' . bin2hex(random_bytes(6)) . '.log';
        $absolutePath = ProjectRoot::get() . '/' . $relativePath;

        putenv('LOG_FILE=' . $relativePath);
        putenv('LOG_LEVEL=debug');

        try {
            $logger = new AsyncJsonLogger();
            $this->injectEnvironment($logger);

            $logger->error('Login failed', ['user_id' => 42, 'username' => "caf\xE9"]);
            $logger->error("Bad path /tmp/\xFF", ['user_id' => 7]);
            $logger->flush();

            $lines = file($absolutePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            self::assertCount(2, $lines);

            $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Login failed', $first['message']);
            self::assertSame(42, $first['context']['user_id']);
            self::assertSame("caf\u{FFFD}", $first['context']['username']);

            $second = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame("Bad path /tmp/\u{FFFD}", $second['message']);
            self::assertSame(7, $second['context']['user_id']);
        } finally {
            @unlink($absolutePath);
        }
    }

    /**
     * REGRESSION. A worker that finds an ALREADY oversized log must rotate it, not
     * wait to write a megabyte of its own first.
     *
     * The size check is throttled by a per-instance byte counter, which answers
     * "has my writing pushed the file over" and cannot answer "was it over before
     * I started". Nothing resets the file between workers, so on the dev host
     * app.log reached 380 MB — 12x the 32 MB ceiling — with no rotated sibling, a
     * week after the policy shipped: every worker was restarted long before it
     * appended the megabyte that would have made it look.
     */
    #[Test]
    public function a_worker_rotates_a_log_that_was_already_oversized_when_it_started(): void
    {
        ProjectRoot::reset();
        $relativePath = 'var/tmp/async-json-logger-oversized-' . bin2hex(random_bytes(6)) . '.log';
        $absolutePath = ProjectRoot::get() . '/' . $relativePath;

        putenv('LOG_FILE=' . $relativePath);
        putenv('LOG_LEVEL=debug');
        putenv('LOG_MAX_BYTES=1024');

        @mkdir(dirname($absolutePath), 0o755, true);
        file_put_contents($absolutePath, str_repeat('x', 4096));

        try {
            $logger = new AsyncJsonLogger();
            $this->injectEnvironment($logger);

            // One short line — far less than the throttle's slice.
            $logger->error('short');
            $logger->flush();

            $rotated = glob($absolutePath . '.*') ?: [];
            self::assertCount(1, $rotated, 'the oversized log was never moved aside');

            // Moved, not truncated: the bytes that were there have to survive
            // under the rotated name, or rotation is just deletion with a nicer
            // message. rename() also leaves the live path absent until the next
            // write recreates it, which is why nothing is asserted about it here.
            $moved = (string) file_get_contents($rotated[0]);
            self::assertStringContainsString(str_repeat('x', 4096), $moved, 'the bytes that were already there did not survive');
            self::assertStringContainsString('short', $moved, 'the line written before rotation was dropped');
        } finally {
            // tearDown() restores whatever the suite arrived with.
            foreach (glob($absolutePath . '*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    #[Test]
    public function the_stderr_sink_writes_json_lines_where_a_container_collects_them(): void
    {
        ProjectRoot::reset();
        $relativePath = 'var/tmp/async-json-logger-test-' . bin2hex(random_bytes(6)) . '.log';
        $file = ProjectRoot::get() . '/' . $relativePath;
        $stderr = sys_get_temp_dir() . '/async-json-logger-stderr-' . bin2hex(random_bytes(6));

        try {
            foreach (['stderr' => [false, true], 'both' => [true, true], 'file' => [true, false], 'nonsense' => [true, false]] as $sink => [$toFile, $toStderr]) {
                @unlink($file);
                @unlink($stderr);
                putenv('LOG_FILE=' . $relativePath);
                putenv('LOG_LEVEL=debug');
                putenv('LOG_SINK=' . $sink);
                $logger = new AsyncJsonLogger();
                $this->injectEnvironment($logger);
                (new \ReflectionProperty($logger, 'stderrTarget'))->setValue($logger, $stderr);

                $logger->warning('disk almost full', ['_channel' => 'storage', 'free' => '2%']);
                $logger->flush();

                self::assertSame($toFile, is_file($file), "LOG_SINK={$sink}: file");
                self::assertSame($toStderr, is_file($stderr), "LOG_SINK={$sink}: stderr");
                if ($toStderr) {
                    $entry = json_decode(trim((string) file_get_contents($stderr)), true);
                    self::assertSame('disk almost full', $entry['message'] ?? null);
                    self::assertSame('storage', $entry['channel'] ?? null);
                }
            }
        } finally {
            @unlink($file);
            @unlink($stderr);
        }
    }

    #[Test]
    public function log_channels_route_a_channel_to_its_own_file_to_stderr_or_nowhere(): void
    {
        // A channel was a field on the line: every channel went to the one sink.
        ProjectRoot::reset();
        $tag = bin2hex(random_bytes(6));
        $main = "var/tmp/async-json-logger-main-{$tag}.log";
        $audit = "var/log/async-json-logger-audit-{$tag}.log";
        $stderr = sys_get_temp_dir() . "/async-json-logger-stderr-{$tag}";
        $previous = getenv('LOG_CHANNELS');
        try {
            putenv('LOG_FILE=' . $main);
            putenv('LOG_LEVEL=debug');
            putenv('LOG_SINK=file');
            putenv("LOG_CHANNELS=audit={$audit}, security=stderr, sql=off, broken=../../etc/x.log, swoole=var/log/swoole.log");
            $logger = new AsyncJsonLogger();
            $this->injectEnvironment($logger);
            (new \ReflectionProperty($logger, 'stderrTarget'))->setValue($logger, $stderr);

            $logger->info('signed in', ['_channel' => 'audit']);
            $logger->warning('token reused', ['_channel' => 'security']);
            $logger->debug('select 1', ['_channel' => 'sql']);
            $logger->info('no channel');
            $logger->info('unsafe route ignored', ['_channel' => 'broken']);
            $logger->info('framework log refused', ['_channel' => 'swoole']);
            $logger->flush();

            $messages = static fn (string $path): array => is_file($path)
                ? array_map(static fn (string $l): string => json_decode($l, true)['message'], array_filter(explode("\n", (string) file_get_contents($path))))
                : [];
            self::assertSame(['signed in'], $messages(ProjectRoot::get() . '/' . $audit));
            self::assertSame(['token reused'], $messages($stderr));
            self::assertSame(['no channel', 'unsafe route ignored', 'framework log refused'], array_values($messages(ProjectRoot::get() . '/' . $main)), 'sql is off; the rest follows LOG_SINK');

            // A batch (as under Swoole's defer): a file two routes write keeps the logged order.
            @unlink(ProjectRoot::get() . '/' . $main);
            putenv('LOG_CHANNELS=security=both');
            $batched = new AsyncJsonLogger();
            $this->injectEnvironment($batched);
            (new \ReflectionProperty($batched, 'stderrTarget'))->setValue($batched, $stderr);
            (new \ReflectionProperty($batched, 'buffer'))->setValue($batched, [
                ['timestamp' => 't', 'level' => 'info', 'message' => 'A', 'context' => []],
                ['timestamp' => 't', 'level' => 'info', 'message' => 'S', 'context' => [], 'channel' => 'security'],
                ['timestamp' => 't', 'level' => 'info', 'message' => 'B', 'context' => []],
            ]);
            $batched->flush();
            self::assertSame(['A', 'S', 'B'], array_values($messages(ProjectRoot::get() . '/' . $main)));
        } finally {
            $previous === false ? putenv('LOG_CHANNELS') : putenv('LOG_CHANNELS=' . $previous);
            @unlink(ProjectRoot::get() . '/' . $main);
            @unlink(ProjectRoot::get() . '/' . $audit);
            @unlink($stderr);
        }
    }

    // verify:accept-test-change the burst regression moved to tests/Integration/Log/AsyncJsonLoggerBurstTest: it switched Swoole hooks on for the rest of the suite

    private function injectEnvironment(AsyncJsonLogger $logger): void
    {
        $property = new \ReflectionProperty($logger, 'environment');
        $property->setAccessible(true);
        $property->setValue($logger, new Environment(
            appEnv: 'dev',
            appDebug: true,
            appName: 'Semitexa Test',
            appHost: 'localhost',
            appPort: 8000,
            swoolePort: 9502,
            swooleSsePort: 9503,
            swooleHost: '127.0.0.1',
            swooleWorkerNum: 1,
            swooleMaxRequest: 1,
            swooleMaxCoroutine: 1,
            swooleLogFile: 'var/log/swoole.log',
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
        ));
    }

    private function restoreEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            return;
        }

        putenv($key . '=' . $value);
    }
}
