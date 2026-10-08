<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;

/**
 * A handler message names the request and response it runs with. One that
 * does not used to read the missing keys (two PHP warnings) and then fail
 * with a TypeError that said nothing about the message.
 */
final class QueuedHandlerMessageTest extends TestCase
{
    #[Test]
    public function a_message_round_trips(): void
    {
        $message = QueuedHandlerMessage::fromJson((string) json_encode([
            'handlerClass' => 'App\\Handler',
            'requestClass' => 'App\\Request',
            'responseClass' => 'App\\Response',
            'attempts' => 2,
        ]));

        self::assertSame(['App\\Handler', 'App\\Request', 'App\\Response', 2], [$message->handlerClass, $message->requestClass, $message->responseClass, $message->attempts]);
    }

    #[Test]
    public function a_message_without_its_request_class_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queued handler message for "App\\Handler" has no requestClass.');

        QueuedHandlerMessage::fromJson((string) json_encode(['handlerClass' => 'App\\Handler', 'responseClass' => 'App\\Response']));
    }

    #[Test]
    public function a_message_that_is_not_a_json_object_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queued handler message is not a JSON object.');

        QueuedHandlerMessage::fromJson('"App\\\\Handler"');
    }
}
