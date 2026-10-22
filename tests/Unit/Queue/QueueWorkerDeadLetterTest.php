<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use Semitexa\Core\Tests\Support\StaticState;
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

    /** @var list<array{class: class-string, values: array<string, mixed>}> */
    private array $staticState;

    protected function setUp(): void
    {
        // The registry caches instances by name: register() alone would not
        // replace one an earlier test created, and it would outlive this test.
        $this->staticState = [
            StaticState::snapshot(QueueTransportRegistry::class),
            StaticState::snapshot(DeadLetterSpyTransport::class),
        ];
        QueueTransportRegistry::reset();
        DeadLetterSpyTransport::$published = [];
        DeadLetterSpyTransport::$refuse = false;
        QueueTransportRegistry::register(self::TRANSPORT, new DeadLetterSpyTransportFactory());
    }

    protected function tearDown(): void
    {
        array_map([StaticState::class, 'restore'], $this->staticState);
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

    /**
     * REGRESSION (release review). A handler message whose handler class is
     * gone used to be logged and acknowledged — the request lost — instead of
     * reaching the dead letter like one whose handler threw.
     */
    #[Test]
    public function a_handler_class_that_no_longer_exists_is_dead_lettered(): void
    {
        $worker = $this->worker(self::TRANSPORT, 'orders');
        $stats = sys_get_temp_dir() . '/semitexa-dlq-stats-' . bin2hex(random_bytes(4)) . '.json';
        (new ReflectionProperty(QueueWorker::class, 'statsFile'))->setValue($worker, $stats);

        try {
            $worker->processPayload((new QueuedHandlerMessage('App\\Gone\\RemovedHandler', 'App\\Req', 'App\\Res', ['id' => 'r-1'], []))->toJson());
        } finally {
            @unlink($stats);
        }

        self::assertCount(1, DeadLetterSpyTransport::$published);
        [$queue, $payload] = DeadLetterSpyTransport::$published[0];
        self::assertSame('orders.failed', $queue);
        $letter = json_decode($payload, true);
        self::assertSame(['id' => 'r-1'], $letter['requestPayload'] ?? null, 'the dead letter carries the request to replay');
        self::assertStringContainsString('App\\Gone\\RemovedHandler', (string) ($letter['error'] ?? ''));
    }

    /**
     * The dead letter could not be written and the error was only logged: the
     * transport acknowledged the message, and it was gone. It throws now, so
     * the transport hands the message back to be delivered again.
     */
    #[Test]
    public function a_dead_letter_that_cannot_be_written_leaves_the_message_on_the_queue(): void
    {
        $worker = $this->worker(self::TRANSPORT, 'orders');
        $stats = sys_get_temp_dir() . '/semitexa-dlq-stats-' . bin2hex(random_bytes(4)) . '.json';
        (new ReflectionProperty(QueueWorker::class, 'statsFile'))->setValue($worker, $stats);
        DeadLetterSpyTransport::$refuse = true;

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('The dead letter could not be written');
            $worker->processPayload((new QueuedHandlerMessage('App\\Gone\\RemovedHandler', 'App\\Req', 'App\\Res', ['id' => 'r-1'], []))->toJson());
        } finally {
            @unlink($stats);
        }
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
            // verify:accept-test-change ep-queue-monitoring: no per-queue counters from a test (they went to the dev Redis under real-looking queue names)
            'statsResolved' => true,
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

    public static bool $refuse = false;

    public function publish(string $queueName, string $payload): void
    {
        if (self::$refuse) {
            throw new \RuntimeException('nats: connection refused');
        }
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
