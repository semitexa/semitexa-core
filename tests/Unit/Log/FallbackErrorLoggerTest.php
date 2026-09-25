<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\FallbackErrorLogger;

final class FallbackErrorLoggerTest extends TestCase
{
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/fallback-error-logger-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    /** An invalid byte inside a structured value must not blank the whole value. */
    #[Test]
    public function invalid_utf8_in_a_structured_context_value_is_substituted(): void
    {
        FallbackErrorLogger::log('Async result delivery failed', ['payload' => ['name' => "caf\xE9", 'id' => 42]]);

        $line = (string) file_get_contents($this->logFile);

        // Positive first: an empty line would satisfy the negative on its own.
        self::assertStringContainsString("payload={\"name\":\"caf\u{FFFD}\",\"id\":42}", $line);
        self::assertStringNotContainsString('[unserializable]', $line);
    }
}
