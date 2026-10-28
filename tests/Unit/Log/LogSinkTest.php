<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LogSink;

/** LOG_SINK=stdout wrote only to var/log/app.log, and nothing said the value was not understood. */
final class LogSinkTest extends TestCase
{
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/log-sink-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    #[Test]
    public function a_value_that_is_not_a_sink_is_said_and_falls_back_to_the_file(): void
    {
        self::assertSame(LogSink::File, LogSink::fromEnv(' StdOut '));

        self::assertStringContainsString(
            "[Semitexa] LOG_SINK: ignored 'stdout' (it takes file, stderr or both); logging to the file.",
            (string) file_get_contents($this->logFile),
        );
    }

    #[Test]
    public function unset_empty_and_known_values_are_silent(): void
    {
        self::assertSame(LogSink::File, LogSink::fromEnv(null));
        self::assertSame(LogSink::File, LogSink::fromEnv(' '));
        self::assertSame(LogSink::Both, LogSink::fromEnv('BOTH'));
        self::assertSame(LogSink::Stderr, LogSink::fromEnv('stderr'));

        self::assertSame('', (string) @file_get_contents($this->logFile));
    }
}
