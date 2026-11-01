<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Log;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;

/**
 * REGRESSION. Under SWOOLE_HOOK_FILE every coroutine taking LOCK_EX on its own
 * serialised a burst through Swoole's slow contended-lock path: 200 lines took
 * 3.4 s, and leaving workers were held past max_wait_time inside
 * file_put_contents, where cancel() cannot reach. One writer per logger writes
 * the same burst in milliseconds.
 *
 * In a child process: hooks are process-wide, Coroutine\run() activates flags
 * an earlier test only stored, and an active proc_open hook outside a
 * coroutine is an uncatchable fatal. In the suite's own process that killed
 * every later test that runs a console command.
 */
final class AsyncJsonLoggerBurstTest extends TestCase
{
    private const BURST = <<<'PHP'
        <?php
        require $argv[1];
        [, , $lines] = $argv;
        Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $logger = new Semitexa\Core\Log\AsyncJsonLogger();
        $property = new ReflectionProperty($logger, 'environment');
        $property->setValue($logger, new Semitexa\Core\Environment(
            appEnv: 'dev', appDebug: true, appName: 'Semitexa Test', appHost: 'localhost', appPort: 8000,
            swoolePort: 9502, swooleSsePort: 9503, swooleHost: '127.0.0.1', swooleWorkerNum: 1,
            swooleMaxRequest: 1, swooleMaxCoroutine: 1, swooleLogFile: 'var/log/swoole.log', swooleLogLevel: 1,
            swooleSessionTableSize: 1, swooleSessionMaxBytes: 1, swooleSseWorkerTableSize: 1,
            swooleSseDeliverTableSize: 1, swooleSsePayloadMaxBytes: 1, corsAllowOrigin: '*',
            corsAllowMethods: 'GET', corsAllowHeaders: 'Content-Type', corsAllowCredentials: false,
        ));
        $startedAt = microtime(true);
        Swoole\Coroutine\run(static function () use ($logger, $lines): void {
            for ($i = 0; $i < (int) $lines; $i++) {
                Swoole\Coroutine::create(static function () use ($logger, $i): void {
                    $logger->warning('burst', ['i' => $i]);
                });
            }
        });
        echo microtime(true) - $startedAt;
        PHP;

    #[Test]
    public function a_burst_of_coroutines_logging_at_once_is_written_without_lock_contention(): void
    {
        $relativePath = 'var/tmp/async-json-logger-burst-' . bin2hex(random_bytes(6));
        $absolutePath = ProjectRoot::get() . '/' . $relativePath . '.log';
        $script = ProjectRoot::get() . '/' . $relativePath . '.php';
        @mkdir(dirname($script), 0o755, true);
        file_put_contents($script, self::BURST);
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';

        try {
            $process = proc_open(
                [PHP_BINARY, $script, $autoload, '200'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                ProjectRoot::get(),
                // Rotation off: an inherited LOG_MAX_BYTES below one record would move
                // the live file aside, and only the live file is read below.
                ['LOG_FILE' => $relativePath . '.log', 'LOG_LEVEL' => 'debug', 'LOG_MAX_BYTES' => '0'] + getenv(),
            );
            self::assertIsResource($process);
            $took = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'the burst process failed: ' . $errors);

            $lines = file($absolutePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            $written = array_map(
                static fn (string $line): int => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['context']['i'],
                $lines,
            );
            sort($written);
            // Every index once: a count alone passes with one line lost and another doubled.
            self::assertSame(range(0, 199), $written, 'a line handed to the writer in progress was lost');
            self::assertIsNumeric($took, 'the burst process did not report its time: ' . $took);
            self::assertLessThan(1.0, (float) $took, 'the burst was written one contended lock at a time');
        } finally {
            @unlink($script);
            @unlink($absolutePath);
        }
    }
}
