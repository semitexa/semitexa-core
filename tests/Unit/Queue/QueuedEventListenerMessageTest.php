<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\Message\QueuedEventListenerMessage;

/**
 * An event message names its listener and event. One that did not, or was not
 * a JSON object at all, failed with a TypeError that said nothing about the
 * message, and that is what its dead letter recorded.
 */
final class QueuedEventListenerMessageTest extends TestCase
{
    #[Test]
    public function a_message_round_trips(): void
    {
        $message = QueuedEventListenerMessage::fromJson((string) json_encode([
            'listenerClass' => 'App\\Listener',
            'eventClass' => 'App\\Event',
            'eventPayload' => ['id' => 7],
            'attempts' => 2,
            'maxRetries' => '3',
            'retryDelay' => 5,
            'publishedAt' => 1767225600000,
            'messageId' => 'm-1',
        ]));

        self::assertSame(
            ['App\\Listener', 'App\\Event', ['id' => 7], 2, 3, 5, 1767225600000, 'm-1'],
            [$message->listenerClass, $message->eventClass, $message->eventPayload, $message->attempts, $message->maxRetries, $message->retryDelay, $message->publishedAt, $message->messageId],
        );
    }

    #[Test]
    public function a_message_without_its_listener_class_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queued event message has no listenerClass.');

        QueuedEventListenerMessage::fromJson((string) json_encode(['eventClass' => 'App\\Event']));
    }

    #[Test]
    public function a_message_without_its_event_class_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queued event message has no eventClass.');

        QueuedEventListenerMessage::fromJson((string) json_encode(['listenerClass' => 'App\\Listener']));
    }

    #[Test]
    public function a_message_that_is_not_a_json_object_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queued event message is not a JSON object.');

        QueuedEventListenerMessage::fromJson('"App\\\\Listener"');
    }

    #[Test]
    public function an_event_payload_that_is_not_an_object_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queued event message has an eventPayload that is not an object.');

        QueuedEventListenerMessage::fromJson((string) json_encode(['listenerClass' => 'App\\Listener', 'eventClass' => 'App\\Event', 'eventPayload' => 'x']));
    }
}
