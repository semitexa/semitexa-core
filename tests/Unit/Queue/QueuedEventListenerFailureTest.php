<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Event\EventDispatcher;
use Semitexa\Core\Queue\Message\QueuedEventListenerMessage;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Queue\QueueWorker;
use Semitexa\Core\Tests\Support\StaticState;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * REGRESSION. The docs promise a Queued listener "automatic retry on failure"
 * and a dead-letter queue, and recommend it for "work that must not be lost:
 * payment processing, third-party webhook calls". The worker logged a
 * throwing listener and acknowledged the message: the event was gone, no
 * retry, no dead letter. Found while proving each claimed capability with a
 * test (tk-parity-proof-gaps). A listener now gets what a queued handler has
 * always had: maxRetries attempts, then `<queue>.failed`.
 */
final class QueuedEventListenerFailureTest extends TestCase
{
    private const TRANSPORT = 'queued-listener-spy';

    /** @var list<array{class: class-string, values: array<string, mixed>}> */
    private array $staticState;

    private string $statsFile;

    protected function setUp(): void
    {
        $this->staticState = [
            StaticState::snapshot(QueueTransportRegistry::class),
            StaticState::snapshot(QueuedListenerSpyTransport::class),
        ];
        QueueTransportRegistry::reset();
        QueuedListenerSpyTransport::$published = [];
        QueueTransportRegistry::register(self::TRANSPORT, new QueuedListenerSpyTransportFactory());
        QueuedListenerFixture::$received = [];
        QueuedListenerFixture::$failWith = null;
        $this->statsFile = sys_get_temp_dir() . '/semitexa-queue-stats-' . bin2hex(random_bytes(4)) . '.json';
        // The worker resolves listeners from the container, which is sealed
        // after boot; the fixture goes into its readonly store for this test.
        $this->store()->readonly[QueuedListenerFixture::class] = new QueuedListenerFixture();
    }

    protected function tearDown(): void
    {
        unset($this->store()->readonly[QueuedListenerFixture::class]);
        array_map([StaticState::class, 'restore'], $this->staticState);
        @unlink($this->statsFile);
    }

    #[Test]
    public function a_listener_that_throws_is_dead_lettered_instead_of_lost(): void
    {
        QueuedListenerFixture::$failWith = 'payment gateway timeout';

        $this->worker('payments')->processPayload($this->message(maxRetries: 0));

        self::assertCount(1, QueuedListenerSpyTransport::$published, 'the failed event went nowhere');
        [$queue, $payload] = QueuedListenerSpyTransport::$published[0];
        self::assertSame('payments.failed', $queue);
        $letter = json_decode($payload, true);
        self::assertSame(QueuedListenerFixture::class, $letter['listenerClass'] ?? null);
        self::assertSame(['orderId' => 'o-42'], $letter['eventPayload'] ?? null, 'the dead letter must carry the event to replay');
        self::assertSame('payment gateway timeout', $letter['error'] ?? null);
    }

    #[Test]
    public function a_listener_with_retries_left_goes_back_on_its_queue(): void
    {
        QueuedListenerFixture::$failWith = 'transient';

        $this->worker('payments')->processPayload($this->message(maxRetries: 1));

        self::assertCount(1, QueuedListenerSpyTransport::$published);
        [$queue, $payload] = QueuedListenerSpyTransport::$published[0];
        self::assertSame('payments', $queue, 'a retry is republished on the queue being consumed');
        self::assertSame(1, json_decode($payload, true)['attempts'] ?? null);
    }

    #[Test]
    public function a_listener_that_succeeds_gets_the_event_back_and_nothing_is_republished(): void
    {
        $this->worker('payments')->processPayload($this->message(maxRetries: 2));

        self::assertSame(['o-42'], array_map(static fn (QueuedListenerEvent $e): string => $e->getOrderId(), QueuedListenerFixture::$received));
        self::assertSame([], QueuedListenerSpyTransport::$published);
    }

    #[Test]
    public function the_dispatcher_carries_the_listeners_retry_policy_into_the_message(): void
    {
        $dispatcher = (new \ReflectionClass(EventDispatcher::class))->newInstanceWithoutConstructor();
        $enqueue = new \ReflectionMethod(EventDispatcher::class, 'enqueueListener');
        $event = new QueuedListenerEvent();
        $event->setOrderId('o-7');

        $enqueue->invoke($dispatcher, [
            'class' => QueuedListenerFixture::class,
            'event' => QueuedListenerEvent::class,
            'execution' => 'queued',
            'transport' => self::TRANSPORT,
            'queue' => 'payments',
            'priority' => 0,
            'maxRetries' => 3,
            'retryDelay' => 0,
        ], $event);

        self::assertCount(1, QueuedListenerSpyTransport::$published);
        $message = QueuedEventListenerMessage::fromJson(QueuedListenerSpyTransport::$published[0][1]);
        self::assertSame(3, $message->maxRetries);
        self::assertSame(['orderId' => 'o-7'], $message->eventPayload);
        self::assertSame(0, $message->attempts);
    }

    #[Test]
    public function a_message_queued_before_the_retry_fields_existed_still_decodes(): void
    {
        $message = QueuedEventListenerMessage::fromJson((string) json_encode([
            'type' => 'event',
            'listenerClass' => QueuedListenerFixture::class,
            'eventClass' => QueuedListenerEvent::class,
            'eventPayload' => ['orderId' => 'o-1'],
            'queuedAt' => '2026-10-18T00:00:00+00:00',
        ]));

        self::assertSame([0, 0, 0], [$message->attempts, $message->maxRetries, $message->retryDelay]);
    }

    /**
     * REGRESSION (release review). A message that fails before the listener
     * runs — its class is gone, or its event cannot be rebuilt (a constructor
     * with required arguments) — was logged and acknowledged: no retry, no
     * dead letter, the event lost on every delivery.
     */
    #[Test]
    public function a_listener_class_that_no_longer_exists_is_dead_lettered(): void
    {
        $payload = (new QueuedEventListenerMessage(
            listenerClass: 'App\\Gone\\RemovedListener',
            eventClass: QueuedListenerEvent::class,
            eventPayload: ['orderId' => 'o-9'],
        ))->toJson();

        $this->worker('payments')->processPayload($payload);

        self::assertCount(1, QueuedListenerSpyTransport::$published);
        [$queue, $letter] = QueuedListenerSpyTransport::$published[0];
        self::assertSame('payments.failed', $queue);
        $letter = json_decode($letter, true);
        self::assertSame(['orderId' => 'o-9'], $letter['eventPayload'] ?? null);
        self::assertStringContainsString('App\\Gone\\RemovedListener', (string) ($letter['error'] ?? ''));
    }

    #[Test]
    public function an_event_that_cannot_be_rebuilt_is_retried_then_dead_lettered(): void
    {
        $payload = fn (int $attempts): string => (new QueuedEventListenerMessage(
            listenerClass: QueuedListenerFixture::class,
            eventClass: QueuedListenerEventWithRequiredArgument::class,
            eventPayload: ['orderId' => 'o-3'],
            attempts: $attempts,
            maxRetries: 1,
        ))->toJson();

        $this->worker('payments')->processPayload($payload(0));
        $this->worker('payments')->processPayload($payload(1));

        self::assertSame(['payments', 'payments.failed'], array_column(QueuedListenerSpyTransport::$published, 0), 'one retry, then the dead letter');
        $letter = json_decode(QueuedListenerSpyTransport::$published[1][1], true);
        self::assertSame(QueuedListenerEventWithRequiredArgument::class, $letter['eventClass'] ?? null);
        self::assertNotSame('', (string) ($letter['error'] ?? ''));
    }

    #[Test]
    public function a_payload_that_is_not_a_message_is_dead_lettered_as_it_came(): void
    {
        $this->worker('payments')->processPayload('{"type": "event", broken');

        self::assertCount(1, QueuedListenerSpyTransport::$published);
        [$queue, $letter] = QueuedListenerSpyTransport::$published[0];
        self::assertSame('payments.failed', $queue);
        $letter = json_decode($letter, true);
        self::assertSame('{"type": "event", broken', $letter['raw'] ?? null, 'kept byte for byte, to be read or replayed');
        self::assertNotSame('', (string) ($letter['error'] ?? ''));
    }

    private function message(int $maxRetries): string
    {
        return (new QueuedEventListenerMessage(
            listenerClass: QueuedListenerFixture::class,
            eventClass: QueuedListenerEvent::class,
            eventPayload: ['orderId' => 'o-42'],
            maxRetries: $maxRetries,
        ))->toJson();
    }

    private function worker(string $queue): QueueWorker
    {
        $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
        foreach ([
            'currentTransport' => self::TRANSPORT,
            'currentQueue' => $queue,
            'statsFile' => $this->statsFile,
            'output' => new BufferedOutput(),
        ] as $field => $value) {
            (new ReflectionProperty(QueueWorker::class, $field))->setValue($worker, $value);
        }

        return $worker;
    }

    private function store(): \Semitexa\Core\Container\Store\InstanceStore
    {
        $container = ContainerFactory::get();
        self::assertInstanceOf(SemitexaContainer::class, $container);

        return (new ReflectionProperty(SemitexaContainer::class, 'instanceStore'))->getValue($container);
    }
}

/** Getters and setters: how the framework serializes an event onto a queue and back. */
final class QueuedListenerEvent
{
    private string $orderId = '';

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }
}

/** An event the worker cannot rebuild: it hydrates through a constructor without arguments. */
final class QueuedListenerEventWithRequiredArgument
{
    public function __construct(private string $orderId)
    {
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }
}

final class QueuedListenerFixture
{
    /** @var list<QueuedListenerEvent> */
    public static array $received = [];

    public static ?string $failWith = null;

    public function handle(QueuedListenerEvent $event): void
    {
        if (self::$failWith !== null) {
            throw new \RuntimeException(self::$failWith);
        }
        self::$received[] = $event;
    }
}

final class QueuedListenerSpyTransport implements QueueTransportInterface
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

final class QueuedListenerSpyTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new QueuedListenerSpyTransport();
    }
}
