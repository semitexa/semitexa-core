<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Queue\QueueWorker;

/**
 * What the journal is told about one consumed message.
 *
 * Three things went wrong here and each was caught in review of
 * semitexa-core#129: a retried attempt reported success, an operational
 * follow-up line replaced the failure reason, and the truncation reached for
 * mbstring inside a finally block on a host that need not have it.
 */
final class QueueWorkerJournalOutcomeTest extends TestCase
{
    #[Test]
    public function the_first_error_survives_a_later_warning(): void
    {
        $worker = $this->worker();

        $this->log($worker, '❌ Error executing handler: connection refused', 'error');
        $this->log($worker, '⚠️  Message moved to DLQ', 'warning');

        self::assertSame(
            'Error executing handler: connection refused',
            $this->read($worker, 'messageProblem'),
            'the journal must carry the failure reason, not the operational follow-up',
        );
    }

    #[Test]
    public function a_warning_still_records_a_reason_when_nothing_failed_yet(): void
    {
        $worker = $this->worker();

        $this->log($worker, '⚠️  Handler App\\Nope not found', 'warning');

        self::assertSame('Handler App\\Nope not found', $this->read($worker, 'messageProblem'));
    }

    #[Test]
    public function truncation_needs_no_mbstring_and_keeps_utf8_whole(): void
    {
        $method = new ReflectionMethod(QueueWorker::class, 'truncate');

        $cut = $method->invoke(null, str_repeat('я', 300));

        self::assertSame(200, mb_strlen($cut), 'the limit counts characters');
        self::assertSame(str_repeat('я', 200), $cut, 'a multi-byte character is never split in half');
        self::assertSame('short', $method->invoke(null, 'short'));

        // Invalid UTF-8 makes PCRE fail rather than throw; the fallback still returns bytes.
        $invalid = $method->invoke(null, "\xC3\x28" . str_repeat('a', 400));
        self::assertNotSame('', $invalid);
        self::assertLessThanOrEqual(200, strlen($invalid));
    }

    private function worker(): QueueWorker
    {
        $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
        foreach (['messageProblem' => null, 'messageStatus' => null] as $field => $value) {
            (new ReflectionProperty(QueueWorker::class, $field))->setValue($worker, $value);
        }
        (new ReflectionProperty(QueueWorker::class, 'messageProblemIsError'))->setValue($worker, false);

        return $worker;
    }

    private function log(QueueWorker $worker, string $message, string $level): void
    {
        (new ReflectionMethod(QueueWorker::class, 'log'))->invoke($worker, $message, $level);
    }

    private function read(QueueWorker $worker, string $field): mixed
    {
        return (new ReflectionProperty(QueueWorker::class, $field))->getValue($worker);
    }
}
