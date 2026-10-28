<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\DeadLetter;
use Semitexa\Core\Queue\Message\QueuedEventListenerMessage;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;

/** One job could not be followed from attempt to attempt to its dead letter. */
final class QueueMessageIdTest extends TestCase
{
    #[Test]
    public function a_message_keeps_its_id_through_retries_and_its_dead_letter(): void
    {
        $message = new QueuedHandlerMessage('H', 'Req', 'Res', [], []);
        self::assertMatchesRegularExpression('/\Am-[0-9a-f]{16}\z/', $message->messageId);
        self::assertNotSame($message->messageId, (new QueuedHandlerMessage('H', 'Req', 'Res', [], []))->messageId);

        // A retry is the same object published again; a worker decodes it.
        $message->attempts++;
        $retried = QueuedHandlerMessage::fromJson($message->toJson());
        self::assertSame($message->messageId, $retried->messageId);

        $letter = DeadLetter::fromStored('7', 'jobs', $retried->toJson());
        self::assertSame($message->messageId, json_decode($letter->retryPayload(), true)['messageId'] ?? null, 'a dead letter retried is the same job');

        $listener = new QueuedEventListenerMessage('L', 'E', []);
        self::assertSame($listener->messageId, QueuedEventListenerMessage::fromJson($listener->toJson())->messageId);
    }

    #[Test]
    public function a_message_queued_before_ids_gets_one_when_it_is_read(): void
    {
        $legacy = '{"type":"handler","handler":"H","requestClass":"Req","responseClass":"Res","requestPayload":[],"responsePayload":[]}';

        self::assertStringStartsWith('m-', QueuedHandlerMessage::fromJson($legacy)->messageId);
    }
}
