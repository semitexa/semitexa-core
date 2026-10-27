<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\DeadLetter;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;

final class DeadLetterTest extends TestCase
{
    #[Test]
    public function a_stored_dead_letter_reads_its_target_and_failure_and_retries_fresh(): void
    {
        $stored = (string) json_encode([
            'type' => 'handler', 'handler' => 'App\\Jobs\\Invoice', 'requestClass' => 'R', 'responseClass' => 'S',
            'attempts' => 3, 'error' => 'SMTP down', 'failed_at' => '2026-10-21T00:00:00+00:00', 'note' => 'ü/x',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $letter = DeadLetter::fromStored('7', 'mail', $stored);

        self::assertSame(['App\\Jobs\\Invoice', 3, 'SMTP down', true], [$letter->target, $letter->attempts, $letter->error, $letter->retryable]);
        self::assertSame('2026-10-21T00:00:00+00:00', $letter->failedAt?->format(DATE_ATOM));

        $retry = json_decode($letter->retryPayload(), true);
        self::assertSame(0, $retry['attempts']);
        self::assertArrayNotHasKey('error', $retry);
        self::assertArrayNotHasKey('failed_at', $retry);
        self::assertSame('ü/x', $retry['note']);
        self::assertGreaterThan(0, $retry['publishedAt']);
        self::assertSame('App\\Jobs\\Invoice', QueuedHandlerMessage::fromJson($letter->retryPayload())->handlerClass);
    }

    #[Test]
    public function an_unreadable_payload_can_only_be_forgotten(): void
    {
        $letter = DeadLetter::fromStored('8', 'mail', '{"raw":"{oops","error":"not a JSON object"}');

        self::assertFalse($letter->retryable);
        self::assertSame('not a JSON object', $letter->error);
        $this->expectException(\LogicException::class);
        $letter->retryPayload();
    }

    /** A stored error that was not a string printed as "Array", with a warning. */
    #[Test]
    public function an_unreadable_payload_keeps_only_a_string_error_and_a_non_object_is_unreadable(): void
    {
        $letter = DeadLetter::fromStored('9', 'mail', '{"raw":"{oops","error":["nested"],"failed_at":"2026-10-21T00:00:05+00:00"}');
        self::assertSame(['', '2026-10-21T00:00:05+00:00'], [$letter->error, $letter->failedAt?->format(DATE_ATOM)]);

        $scalar = DeadLetter::fromStored('10', 'mail', '"just text"');
        self::assertSame(['unreadable', null, false], [$scalar->error, $scalar->failedAt, $scalar->retryable]);
    }

    #[Test]
    public function published_at_is_stamped_and_an_older_message_falls_back_to_queued_at(): void
    {
        self::assertGreaterThan(1_700_000_000_000, (new QueuedHandlerMessage('H', 'R', 'S', [], []))->publishedAt);

        $old = QueuedHandlerMessage::fromJson((string) json_encode(['handler' => 'H', 'requestClass' => 'R', 'responseClass' => 'S', 'queuedAt' => '2026-10-21T00:00:05+00:00']));
        self::assertSame(strtotime('2026-10-21T00:00:05+00:00') * 1000, $old->publishedAt);
    }
}
