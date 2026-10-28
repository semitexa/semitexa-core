<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Queue\DelayedQueueTransportInterface;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Queue\QueueWorker;
use Semitexa\Core\Tests\Support\StaticState;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A retry used to wait its retryDelay inside the consume loop: one failing job
 * with a 30 s delay held every job behind it for 30 s per attempt.
 */
final class QueueWorkerDelayedRetryTest extends TestCase
{
    private const TRANSPORT = 'delayed-retry-spy';

    /** @var list<array{class: class-string, values: array<string, mixed>}> */
    private array $staticState;

    protected function setUp(): void
    {
        $this->staticState = [
            StaticState::snapshot(QueueTransportRegistry::class),
            StaticState::snapshot(DelayedRetrySpyTransport::class),
        ];
        QueueTransportRegistry::reset();
        DelayedRetrySpyTransport::$published = [];
        DelayedRetrySpyTransport::$delayed = [];
        QueueTransportRegistry::register(self::TRANSPORT, new DelayedRetrySpyTransportFactory());
    }

    protected function tearDown(): void
    {
        array_map([StaticState::class, 'restore'], $this->staticState);
    }

    #[Test]
    public function a_retry_is_handed_to_a_transport_that_can_hold_it_back_without_waiting(): void
    {
        $message = new QueuedHandlerMessage('App\\H', 'App\\Req', 'App\\Res', [], [], maxRetries: 3, retryDelay: 30);

        $started = microtime(true);
        $this->retry($this->worker(), $message);

        self::assertLessThan(1.0, microtime(true) - $started, 'the worker waited for the retry delay');
        self::assertSame([], DelayedRetrySpyTransport::$published);
        self::assertCount(1, DelayedRetrySpyTransport::$delayed);
        [$queue, $payload, $delay] = DelayedRetrySpyTransport::$delayed[0];
        self::assertSame('orders', $queue);
        self::assertSame(30, $delay);
        $sent = json_decode($payload, true);
        self::assertSame(1, $sent['attempts'] ?? null);
        self::assertGreaterThan((int) floor(microtime(true) * 1000) + 29_000, $sent['publishedAt'] ?? 0, 'its wait counts from when it is due');
    }

    #[Test]
    public function a_retry_without_a_delay_is_published_at_once(): void
    {
        $message = new QueuedHandlerMessage('App\\H', 'App\\Req', 'App\\Res', [], [], maxRetries: 3, retryDelay: 0);

        $this->retry($this->worker(), $message);

        self::assertCount(1, DelayedRetrySpyTransport::$published);
        self::assertSame([], DelayedRetrySpyTransport::$delayed);
    }

    private function worker(): QueueWorker
    {
        $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
        foreach ([
            'currentTransport' => self::TRANSPORT,
            'currentQueue' => 'orders',
            'messageProblem' => null,
            'messageProblemIsError' => false,
            'output' => new BufferedOutput(),
            'statsResolved' => true,
        ] as $field => $value) {
            (new ReflectionProperty(QueueWorker::class, $field))->setValue($worker, $value);
        }

        return $worker;
    }

    private function retry(QueueWorker $worker, QueuedHandlerMessage $message): void
    {
        (new ReflectionMethod(QueueWorker::class, 'retryOrDeadLetter'))->invoke($worker, $message, 'boom');
    }
}

final class DelayedRetrySpyTransport implements DelayedQueueTransportInterface
{
    /** @var list<array{string, string}> */
    public static array $published = [];

    /** @var list<array{string, string, int}> */
    public static array $delayed = [];

    public function publish(string $queueName, string $payload): void
    {
        self::$published[] = [$queueName, $payload];
    }

    public function publishDelayed(string $queueName, string $payload, int $delaySeconds): void
    {
        self::$delayed[] = [$queueName, $payload, $delaySeconds];
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}

final class DelayedRetrySpyTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new DelayedRetrySpyTransport();
    }
}
