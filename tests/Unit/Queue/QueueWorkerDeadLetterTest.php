<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Queue\QueueWorker;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An exhausted message is dead-lettered next to the queue it was consumed
 * from. Publishing it on the process defaults instead put it on the worker's
 * own in-memory transport whenever EVENTS_ASYNC was unset, where it vanished
 * with the process while the log said it had been moved.
 */
final class QueueWorkerDeadLetterTest extends TestCase
{
    private const TRANSPORT = 'dlq-test-spy';

    protected function setUp(): void
    {
        DeadLetterSpyTransport::$published = [];
        QueueTransportRegistry::register(self::TRANSPORT, new DeadLetterSpyTransportFactory());
    }

    #[Test]
    public function exhausted_message_is_dead_lettered_on_the_consumed_transport_and_queue(): void
    {
        $worker = $this->worker(self::TRANSPORT, 'orders');

        $this->deadLetter($worker, new QueuedHandlerMessage('App\\H', 'App\\Req', 'App\\Res', [], []), 'boom');

        self::assertCount(1, DeadLetterSpyTransport::$published, 'the consumed transport must receive the dead letter');
        [$queue, $payload] = DeadLetterSpyTransport::$published[0];
        self::assertSame('orders.failed', $queue);
        self::assertSame('boom', json_decode($payload, true)['error'] ?? null);
    }

    private function worker(?string $transport, ?string $queue): QueueWorker
    {
        $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
        foreach ([
            'currentTransport' => $transport,
            'currentQueue' => $queue,
            'messageProblem' => null,
            'messageProblemIsError' => false,
            'output' => new BufferedOutput(),
        ] as $field => $value) {
            (new ReflectionProperty(QueueWorker::class, $field))->setValue($worker, $value);
        }

        return $worker;
    }

    private function deadLetter(QueueWorker $worker, QueuedHandlerMessage $message, string $error): void
    {
        (new ReflectionMethod(QueueWorker::class, 'moveToDeadLetterQueue'))->invoke($worker, $message, $error);
    }
}

final class DeadLetterSpyTransport implements QueueTransportInterface
{
    /** @var list<array{string, string}> */
    public static array $published = [];

    public function publish(string $queueName, string $payload): void
    {
        self::$published[] = [$queueName, $payload];
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}

final class DeadLetterSpyTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new DeadLetterSpyTransport();
    }
}
