<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\AsyncJsonLogger;
use Semitexa\Core\Support\ProjectRoot;

final class AsyncJsonLoggerTest extends TestCase
{
    private ?string $previousLogFile = null;
    private ?string $previousLogLevel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousLogFile = getenv('LOG_FILE') !== false ? (string) getenv('LOG_FILE') : null;
        $this->previousLogLevel = getenv('LOG_LEVEL') !== false ? (string) getenv('LOG_LEVEL') : null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('LOG_FILE', $this->previousLogFile);
        $this->restoreEnv('LOG_LEVEL', $this->previousLogLevel);

        parent::tearDown();
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
            putenv('LOG_MAX_BYTES');
            foreach (glob($absolutePath . '*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

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
