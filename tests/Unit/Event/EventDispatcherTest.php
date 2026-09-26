<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcher;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\SwooleBootstrap;

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

    /** What SwooleBootstrap's onRequest sets on the coroutine a request runs in. */
    private static function markAsRequestCoroutine(): void
    {
        $key = (new \ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
        \Swoole\Coroutine::getContext()[$key] = true;
    }

    /**
     * @param list<array{0: class-string, 1: EventExecution}> $listeners
     */
    private function dispatcherWith(array $listeners): EventDispatcher
    {
        $registryRef = new \ReflectionClass(EventListenerRegistry::class);
        $registry = $registryRef->newInstanceWithoutConstructor();
        $registryRef->getProperty('built')->setValue($registry, true);
        $registryRef->getProperty('listenersByEvent')->setValue($registry, [
            DispatcherProbeEvent::class => array_map(
                static fn (array $l): array => ['class' => $l[0], 'execution' => $l[1]->value, 'event' => DispatcherProbeEvent::class],
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
