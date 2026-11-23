<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue\Fixtures;

use Semitexa\Core\Queue\QueueTransportInterface;

/** Keeps what the worker publishes (its dead letters), consumes nothing. */
final class RecordingQueueTransport implements QueueTransportInterface
{
    /** @var list<array{string, string}> queue name, payload */
    public static array $published = [];

    public function publish(string $queueName, string $payload): void
    {
        self::$published[] = [$queueName, $payload];
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}
