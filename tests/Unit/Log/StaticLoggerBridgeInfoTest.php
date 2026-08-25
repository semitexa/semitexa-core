<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * The INFO rung of the static bridge.
 *
 * WHY IT EXISTS: the bridge exposed error/warning/debug only, though
 * {@see LoggerInterface} has declared `info()` all along. That gap forced any
 * self-healing subsystem into a false choice — `debug()`, which production log
 * levels usually drop, or `warning()`, which log-reading alerters digest into an
 * incident. Announcing recovery from a transient fault belongs on neither
 * (semitexa/semitexa-ssr#100).
 *
 * The behavioural contract mirrors `debug()`, NOT `warning()`: with no logger
 * resolvable it returns silently rather than falling through to
 * `FallbackErrorLogger`. An informational line is never worth an unstructured
 * write to stderr.
 */
final class StaticLoggerBridgeInfoTest extends TestCase
{
    protected function tearDown(): void
    {
        StaticLoggerBridge::reset();
    }

    /** A capturing logger double: records (level, message, context) triples. */
    private function capturingLogger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $lines = [];

            public function error(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }

            public function critical(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'critical', 'message' => $message, 'context' => $context];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            public function info(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }

            public function notice(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'notice', 'message' => $message, 'context' => $context];
            }

            public function debug(string $message, array $context = []): void
            {
                $this->lines[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
            }
        };
    }

    #[Test]
    public function info_reaches_the_logger_at_the_info_level(): void
    {
        $logger = $this->capturingLogger();
        StaticLoggerBridge::set($logger);

        StaticLoggerBridge::info('ssr', 'subscribe loop recovered', ['attempts' => 4]);

        self::assertCount(1, $logger->lines);
        self::assertSame('info', $logger->lines[0]['level']);
        self::assertSame('subscribe loop recovered', $logger->lines[0]['message']);
        self::assertSame(4, $logger->lines[0]['context']['attempts']);
    }

    #[Test]
    public function info_stamps_the_channel_like_every_other_rung(): void
    {
        $logger = $this->capturingLogger();
        StaticLoggerBridge::set($logger);

        StaticLoggerBridge::info('ssr', 'anything');

        self::assertSame('ssr', $logger->lines[0]['context']['_channel']);
    }

    #[Test]
    public function an_explicit_channel_context_is_not_overwritten(): void
    {
        $logger = $this->capturingLogger();
        StaticLoggerBridge::set($logger);

        StaticLoggerBridge::info('ssr', 'anything', ['_channel' => 'caller-chose-this']);

        self::assertSame('caller-chose-this', $logger->lines[0]['context']['_channel']);
    }

    #[Test]
    public function info_is_silent_when_no_logger_resolves(): void
    {
        // Mirrors debug(), not warning(): no FallbackErrorLogger write. An
        // informational line is never worth an unstructured stderr write.
        StaticLoggerBridge::reset();

        StaticLoggerBridge::info('ssr', 'nobody is listening');

        $this->expectNotToPerformAssertions();
    }
}
