<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcher;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\Lifecycle\CarriedContext;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Tests\Support\StaticState;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\Support\CoroutineLocal;

final class EventDispatcherTest extends TestCase
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $callsSnapshot = [];

    private ?LoggerInterface $loggerSnapshot = null;

    protected function setUp(): void
    {
        $this->callsSnapshot = DispatcherProbeLog::$calls;
        $logger = (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->getValue();
        $this->loggerSnapshot = $logger instanceof LoggerInterface ? $logger : null;
        DispatcherProbeLog::$calls = [];
        RebuildProbeListener::$received = null;
        ContextProbeListener::$seen = [];
        $this->errors = [];
        $errors = &$this->errors;
        StaticLoggerBridge::set(new class ($errors) implements LoggerInterface {
            /** @param list<string> $errors */
            public function __construct(private array &$errors) {}
            public function error(string $message, array $context = []): void { $this->errors[] = $message; }
            public function critical(string $message, array $context = []): void { $this->errors[] = $message; }
            public function warning(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        });
    }

    protected function tearDown(): void
    {
        DispatcherProbeLog::$calls = $this->callsSnapshot;
        (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->setValue(null, $this->loggerSnapshot);
    }

    #[Test]
    public function async_listeners_run_in_the_order_they_were_scheduled(): void
    {
        // Coroutine::defer itself is LIFO; an order-dependent listener must not
        // see the second dispatch's event before the first's.
        $dispatcher = $this->dispatcherWith([
            [RecordingProbeListener::class, EventExecution::Async],
            [SecondRecordingProbeListener::class, EventExecution::Async],
        ]);

        \Swoole\Coroutine\run(function () use ($dispatcher): void {
            self::markAsRequestCoroutine();
            $dispatcher->dispatch(new DispatcherProbeEvent());
            $dispatcher->dispatch(new DispatcherProbeEvent());
        });

        self::assertSame([
            RecordingProbeListener::class,
            SecondRecordingProbeListener::class,
            RecordingProbeListener::class,
            SecondRecordingProbeListener::class,
        ], DispatcherProbeLog::$calls);
    }

    #[Test]
    public function an_async_listener_inside_a_swoole_coroutine_runs_after_dispatch_returns(): void
    {
        $dispatcher = $this->dispatcherWith([[RecordingProbeListener::class, EventExecution::Async]]);
        $seenDuringDispatch = null;

        \Swoole\Coroutine\run(function () use ($dispatcher, &$seenDuringDispatch): void {
            self::markAsRequestCoroutine();
            $dispatcher->dispatch(new DispatcherProbeEvent());
            $seenDuringDispatch = DispatcherProbeLog::$calls;
        });

        self::assertSame([], $seenDuringDispatch, 'async listener must not run inside the request');
        self::assertSame([RecordingProbeListener::class], DispatcherProbeLog::$calls);
    }

    #[Test]
    public function an_async_listener_is_resolved_while_the_request_context_is_still_live(): void
    {
        $dispatcher = $this->dispatcherWith([[ConstructedProbeListener::class, EventExecution::Async]]);
        $seenDuringDispatch = null;

        \Swoole\Coroutine\run(function () use ($dispatcher, &$seenDuringDispatch): void {
            self::markAsRequestCoroutine();
            $dispatcher->dispatch(new DispatcherProbeEvent());
            $seenDuringDispatch = DispatcherProbeLog::$calls;
        });

        self::assertSame(['constructed'], $seenDuringDispatch);
        self::assertSame(['constructed', 'handled'], DispatcherProbeLog::$calls);
    }

    #[Test]
    public function a_throwing_async_listener_does_not_propagate_and_is_logged(): void
    {
        $dispatcher = $this->dispatcherWith([[ThrowingProbeListener::class, EventExecution::Async]]);
        $thrown = null;

        \Swoole\Coroutine\run(function () use ($dispatcher, &$thrown): void {
            self::markAsRequestCoroutine();
            try {
                $dispatcher->dispatch(new DispatcherProbeEvent());
            } catch (\Throwable $e) {
                $thrown = $e;
            }
        });

        self::assertNull($thrown);
        self::assertSame([ThrowingProbeListener::class], DispatcherProbeLog::$calls);
        self::assertNotSame([], $this->errors);
    }

    #[Test]
    public function an_async_listener_in_a_standing_coroutine_runs_inline(): void
    {
        // A consume loop (the ledger's NATS command processor) never exits, so
        // a Coroutine::defer there would never fire and would pin the closure.
        $dispatcher = $this->dispatcherWith([[RecordingProbeListener::class, EventExecution::Async]]);
        $seenDuringDispatch = null;

        \Swoole\Coroutine\run(function () use ($dispatcher, &$seenDuringDispatch): void {
            $dispatcher->dispatch(new DispatcherProbeEvent());
            $seenDuringDispatch = DispatcherProbeLog::$calls;
        });

        self::assertSame([RecordingProbeListener::class], $seenDuringDispatch);
        self::assertSame([RecordingProbeListener::class], DispatcherProbeLog::$calls);
    }

    #[Test]
    public function an_async_listener_outside_a_coroutine_runs_inline(): void
    {
        $dispatcher = $this->dispatcherWith([[RecordingProbeListener::class, EventExecution::Async]]);

        $dispatcher->dispatch(new DispatcherProbeEvent());

        self::assertSame([RecordingProbeListener::class], DispatcherProbeLog::$calls);
    }

    #[Test]
    public function a_throwing_sync_listener_still_propagates_but_post_dispatch_hooks_see_the_event(): void
    {
        $dispatcher = $this->dispatcherWith([[ThrowingProbeListener::class, EventExecution::Sync]]);
        $hooked = [];
        $dispatcher->addPostDispatchHook(static function (object $event) use (&$hooked): void {
            $hooked[] = $event::class;
        });

        try {
            $dispatcher->dispatch(new DispatcherProbeEvent());
            self::fail('a sync listener failure belongs to the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('listener failed', $e->getMessage());
        }

        self::assertSame([DispatcherProbeEvent::class], $hooked);
    }

    #[Test]
    public function a_listener_whose_handle_is_not_public_is_refused_as_a_configuration_error(): void
    {
        // method_exists() alone let a private handle() through, and the call
        // then died with a bare "Call to private method" Error.
        $dispatcher = $this->dispatcherWith([[PrivateHandleProbeListener::class, EventExecution::Sync]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must have a public handle() method');
        $dispatcher->dispatch(new DispatcherProbeEvent());
    }

    /**
     * REGRESSION (tk-queued-mail-sync-without-async). With EVENTS_ASYNC=0 the
     * queue transport is in-memory: one per worker process, and nothing in
     * that process consumes it. A queued listener was published into it and
     * never ran; the message stayed in the worker's memory. Without async
     * events nothing can be queued, so it runs in the dispatch, as a sync one.
     */
    #[Test]
    public function without_async_events_a_queued_listener_runs_in_the_dispatch(): void
    {
        $this->withEnv(['EVENTS_ASYNC' => '0', 'EVENTS_TRANSPORT' => null], function (): void {
            $dispatcher = $this->dispatcherWith([[RecordingProbeListener::class, EventExecution::Queued]]);

            $dispatcher->dispatch(new DispatcherProbeEvent());

            self::assertSame([RecordingProbeListener::class], DispatcherProbeLog::$calls);
        });
    }

    #[Test]
    public function without_async_events_a_failing_queued_listener_is_logged_not_thrown_at_the_caller(): void
    {
        $this->withEnv(['EVENTS_ASYNC' => '0', 'EVENTS_TRANSPORT' => null], function (): void {
            $dispatcher = $this->dispatcherWith([[ThrowingProbeListener::class, EventExecution::Queued]]);

            $dispatcher->dispatch(new DispatcherProbeEvent());

            self::assertSame([ThrowingProbeListener::class], DispatcherProbeLog::$calls);
            self::assertSame(['Async event listener failed'], $this->errors);
        });
    }

    #[Test]
    public function with_a_transport_a_worker_reads_a_queued_listener_is_published_not_run(): void
    {
        $state = StaticState::snapshot(QueueTransportRegistry::class);
        DispatcherSpyTransport::$published = [];
        QueueTransportRegistry::register('dispatcher-spy', new DispatcherSpyTransportFactory());
        try {
            $this->withEnv(['EVENTS_ASYNC' => '1', 'EVENTS_TRANSPORT' => 'dispatcher-spy'], function (): void {
                $dispatcher = $this->dispatcherWith([[RecordingProbeListener::class, EventExecution::Queued]]);

                $dispatcher->dispatch(new DispatcherProbeEvent());

                self::assertSame([], DispatcherProbeLog::$calls);
                self::assertSame(['events'], array_column(DispatcherSpyTransport::$published, 0));
            });
        } finally {
            StaticState::restore($state);
        }
    }

    /**
     * The replay sandbox registers a capturing transport under 'in-memory':
     * what decides is the transport the registry hands out, not its name, so
     * a sandboxed request still captures its queued listeners.
     */
    #[Test]
    public function a_transport_registered_under_the_in_memory_name_still_receives_the_message(): void
    {
        $state = StaticState::snapshot(QueueTransportRegistry::class);
        DispatcherSpyTransport::$published = [];
        // As ReplayRunner::stubQueueTransports() does it.
        QueueTransportRegistry::reset();
        QueueTransportRegistry::initialize();
        QueueTransportRegistry::register('in-memory', new DispatcherSpyTransportFactory());
        try {
            $this->withEnv(['EVENTS_ASYNC' => '0', 'EVENTS_TRANSPORT' => null], function (): void {
                $dispatcher = $this->dispatcherWith([[RecordingProbeListener::class, EventExecution::Queued]]);

                $dispatcher->dispatch(new DispatcherProbeEvent());

                self::assertSame([], DispatcherProbeLog::$calls);
                self::assertCount(1, DispatcherSpyTransport::$published);
            });
        } finally {
            StaticState::restore($state);
        }
    }

    /** What a worker would get: the event rebuilt from its getters, not the object the caller still holds. */
    #[Test]
    public function without_async_events_a_queued_listener_gets_the_event_as_a_worker_would(): void
    {
        $this->withEnv(['EVENTS_ASYNC' => '0', 'EVENTS_TRANSPORT' => null], function (): void {
            $dispatcher = $this->dispatcherWith([[RebuildProbeListener::class, EventExecution::Queued]], RebuildProbeEvent::class);
            $event = new RebuildProbeEvent();
            $event->setOrderId('o-7');
            $event->unreadable = 'no getter';

            $dispatcher->dispatch($event);

            self::assertNotSame($event, RebuildProbeListener::$received);
            self::assertSame('o-7', RebuildProbeListener::$received?->getOrderId());
            self::assertSame('', RebuildProbeListener::$received->unreadable, 'a value without a getter does not travel');
        });
    }

    #[Test]
    public function without_async_events_an_event_a_worker_could_not_rebuild_is_logged_not_run(): void
    {
        $this->withEnv(['EVENTS_ASYNC' => '0', 'EVENTS_TRANSPORT' => null], function (): void {
            $dispatcher = $this->dispatcherWith([[RecordingProbeListener::class, EventExecution::Queued]], ConstructorArgumentProbeEvent::class);

            $dispatcher->dispatch(new ConstructorArgumentProbeEvent('o-1'));

            self::assertSame([], DispatcherProbeLog::$calls);
            self::assertSame(['Async event listener failed'], $this->errors);
        });
    }

    /**
     * tk-held-open-first-run-and-listener-context. The request clears its
     * tenant and user once the response is out, and the listener runs after
     * that: it got the defaults. It gets the request's.
     */
    #[Test]
    public function an_async_listener_runs_with_the_requests_context_after_the_request_cleared_it(): void
    {
        $dispatcher = $this->dispatcherWith([[ContextProbeListener::class, EventExecution::Async]]);
        CarriedContext::carry('test.tenant');
        \Swoole\Coroutine\run(static function () use ($dispatcher): void {
            self::markAsRequestCoroutine();
            CoroutineLocal::set('test.tenant', 'acme');
            $dispatcher->dispatch(new DispatcherProbeEvent());
            // What the request does once the response is out.
            CoroutineLocal::remove('test.tenant');
        });

        self::assertSame(['acme'], ContextProbeListener::$seen);
    }

    /**
     * A queued listener no worker reads runs in the dispatch, and a worker has
     * no request: in a coroutine that carries its parent's context (an SSE
     * child, say) it must not see it, or it works here and not behind a queue.
     */
    #[Test]
    public function without_async_events_a_queued_listener_runs_without_the_context_live_where_it_is_dispatched(): void
    {
        $this->withEnv(['EVENTS_ASYNC' => '0', 'EVENTS_TRANSPORT' => null], function (): void {
            $dispatcher = $this->dispatcherWith([[ContextProbeListener::class, EventExecution::Queued]]);
            CarriedContext::carry('test.tenant');
            $after = null;
            \Swoole\Coroutine\run(static function () use ($dispatcher, &$after): void {
                CoroutineLocal::set('test.tenant', 'acme');
                $dispatcher->dispatch(new DispatcherProbeEvent());
                $after = CoroutineLocal::get('test.tenant');
            });

            self::assertSame([null], ContextProbeListener::$seen);
            self::assertSame('acme', $after, 'and the coroutine has its own back');
        });
    }

    /** @param array<string, ?string> $values null unsets */
    private function withEnv(array $values, callable $run): void
    {
        $saved = [];
        foreach ($values as $key => $value) {
            $saved[$key] = getenv($key);
            putenv($value === null ? $key : "{$key}={$value}");
        }
        try {
            $run();
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : "{$key}={$value}");
            }
        }
    }

    /** What SwooleBootstrap's onRequest sets on the coroutine a request runs in. */
    private static function markAsRequestCoroutine(): void
    {
        $key = (new \ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
        \Swoole\Coroutine::getContext()[$key] = true;
    }

    /**
     * @param list<array{0: class-string, 1: EventExecution}> $listeners
     * @param class-string $eventClass
     */
    private function dispatcherWith(array $listeners, string $eventClass = DispatcherProbeEvent::class): EventDispatcher
    {
        $registryRef = new \ReflectionClass(EventListenerRegistry::class);
        $registry = $registryRef->newInstanceWithoutConstructor();
        $registryRef->getProperty('built')->setValue($registry, true);
        $registryRef->getProperty('listenersByEvent')->setValue($registry, [
            $eventClass => array_map(
                static fn (array $l): array => ['class' => $l[0], 'execution' => $l[1]->value, 'event' => $eventClass],
                $listeners,
            ),
        ]);

        $dispatcher = new EventDispatcher();
        (new \ReflectionProperty(EventDispatcher::class, 'eventListenerRegistry'))->setValue($dispatcher, $registry);

        return $dispatcher;
    }
}

final class DispatcherProbeEvent {}

final class DispatcherProbeLog
{
    /** @var list<string> */
    public static array $calls = [];
}

final class RecordingProbeListener
{
    public function handle(DispatcherProbeEvent $event): void
    {
        DispatcherProbeLog::$calls[] = self::class;
    }
}

final class ContextProbeListener
{
    /** @var list<mixed> the carried tenant, each run */
    public static array $seen = [];

    public function handle(DispatcherProbeEvent $event): void
    {
        self::$seen[] = CoroutineLocal::get('test.tenant');
    }
}

final class SecondRecordingProbeListener
{
    public function handle(DispatcherProbeEvent $event): void
    {
        DispatcherProbeLog::$calls[] = self::class;
    }
}

final class ThrowingProbeListener
{
    public function handle(DispatcherProbeEvent $event): void
    {
        DispatcherProbeLog::$calls[] = self::class;
        throw new \RuntimeException('listener failed');
    }
}

final class ConstructedProbeListener
{
    public function __construct()
    {
        DispatcherProbeLog::$calls[] = 'constructed';
    }

    public function handle(DispatcherProbeEvent $event): void
    {
        DispatcherProbeLog::$calls[] = 'handled';
    }
}

final class PrivateHandleProbeListener
{
    private function handle(DispatcherProbeEvent $event): void
    {
        DispatcherProbeLog::$calls[] = self::class;
    }

    /**
     * A public __call() makes [$this, 'handle'] callable although handle()
     * is private — the dispatcher must still refuse the listener.
     *
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        DispatcherProbeLog::$calls[] = self::class . '::__call';

        return null;
    }
}

final class DispatcherSpyTransport implements QueueTransportInterface
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

final class DispatcherSpyTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new DispatcherSpyTransport();
    }
}

final class RebuildProbeEvent
{
    private string $orderId = '';

    public string $unreadable = '';

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }
}

final class RebuildProbeListener
{
    public static ?RebuildProbeEvent $received = null;

    public function handle(RebuildProbeEvent $event): void
    {
        self::$received = $event;
    }
}

final class ConstructorArgumentProbeEvent
{
    public function __construct(private string $orderId)
    {
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }
}
