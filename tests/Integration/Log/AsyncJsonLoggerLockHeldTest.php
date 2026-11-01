<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Log;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;

/**
 * REGRESSION, found by the 72h soak on 2026-10-19. A worker recycled by
 * max_request was killed at max_wait_time, with the requests it was still
 * answering: its logger was parked in file_put_contents(FILE_APPEND|LOCK_EX),
 * waiting for a lock another process held, and a hooked file call cannot be
 * cancelled. The lines it was writing were the drain's own — the errors of the
 * coroutines the drain had just cancelled. One writer per logger (core
 * 5798602) took the contention inside a worker away; the wait on the lock the
 * OTHER workers share was still there.
 *
 * Here another handle — a stand-in for another worker — holds LOCK_EX on the
 * log file for 1.5 s while the logger writes a line. The line must be written
 * without waiting for it.
 *
 * In a child process: hooks are process-wide; see AsyncJsonLoggerBurstTest.
 */
final class AsyncJsonLoggerLockHeldTest extends TestCase
{
    private const HELD = <<<'PHP'
        <?php
        require $argv[1];
        [, , $path] = $argv;
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
        $took = null;
        Swoole\Coroutine\run(static function () use ($logger, $path, &$took): void {
            @mkdir(dirname($path), 0o755, true);
            $other = fopen($path, 'ab');
            flock($other, LOCK_EX);
            Swoole\Coroutine::create(static function () use ($other): void {
                Swoole\Coroutine::sleep(1.5);
                flock($other, LOCK_UN);
                fclose($other);
            });
            $startedAt = microtime(true);
            $logger->warning('while another worker holds the log', ['marker' => 'lock-held']);
            $took = microtime(true) - $startedAt;
        });
        echo $took;
        PHP;

    #[Test]
    public function a_line_is_written_while_another_process_holds_the_log_lock(): void
    {
        $relativePath = 'var/tmp/async-json-logger-lock-held-' . bin2hex(random_bytes(6));
        $absolutePath = ProjectRoot::get() . '/' . $relativePath . '.log';
        $script = ProjectRoot::get() . '/' . $relativePath . '.php';
        @mkdir(dirname($script), 0o755, true);
        file_put_contents($script, self::HELD);
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';

        try {
            $process = proc_open(
                [PHP_BINARY, $script, $autoload, $absolutePath],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                ProjectRoot::get(),
                ['LOG_FILE' => $relativePath . '.log', 'LOG_LEVEL' => 'debug', 'LOG_MAX_BYTES' => '0'] + getenv(),
            );
            self::assertIsResource($process);
            $took = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'the child process failed: ' . $errors);

            self::assertIsNumeric($took, 'the child did not report its time: ' . $took);
            self::assertLessThan(0.5, (float) $took, 'the logger waited for a lock another process held');
            self::assertStringContainsString('lock-held', (string) file_get_contents($absolutePath), 'the line was not written');
        } finally {
            @unlink($script);
            @unlink($absolutePath);
        }
    }
}
