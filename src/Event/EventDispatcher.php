<?php

declare(strict_types=1);

namespace Semitexa\Core\Event;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\Support\PayloadSerializer;

/**
 * Single entry point for events: create() builds the event instance (framework-controlled),
 * dispatch() runs all listeners (sync or async via the same queue as payload handlers).
 *
 * @internal Uses ContainerFactory::get() — this is core framework plumbing, not application code.
 */
#[SatisfiesServiceContract(of: EventDispatcherInterface::class)]
final class EventDispatcher implements EventDispatcherInterface
{
    #[InjectAsReadonly]
    protected EventListenerRegistry $eventListenerRegistry;

    /**
     * Callbacks invoked after listener handling has been initiated for every dispatched event.
     * Sync listeners have completed; async and queued listeners may still be running elsewhere.
     * Registered by packages (e.g. semitexa-ledger) at worker boot.
     *
     * @var list<callable(object): void>
     */
    private array $postDispatchHooks = [];
    /**
     * Create an event instance. Use this instead of "new Event()" so the framework
     * can apply initialization, validation, or optimizations now or later.
     */
    /**
     * @param array<string, mixed> $payload
     */
    public function create(string $eventClass, array $payload): object
    {
        $eventClass = ltrim($eventClass, '\\');
        if (!class_exists($eventClass)) {
            throw new \InvalidArgumentException("Event class does not exist: {$eventClass}");
        }

        $reflection = new \ReflectionClass($eventClass);
        if (!$reflection->isInstantiable()) {
            throw new \InvalidArgumentException("Event class is not instantiable: {$eventClass}");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return PayloadSerializer::hydrate($reflection->newInstance(), $payload);
        }

        $instance = $reflection->newInstanceArgs($this->buildConstructorArgs($constructor, $payload, $eventClass));

        return PayloadSerializer::hydrate($instance, $payload);
    }

    /**
     * Register a callback to run after listener handling has been initiated for every dispatched event.
     * Used by semitexa-ledger to hook LedgerWriter into the dispatch pipeline.
     *
     * @param callable(object): void $hook
     */
    public function addPostDispatchHook(callable $hook): void
    {
        $this->postDispatchHooks[] = $hook;
    }

    /**
     * Dispatch event to all registered listeners. Sync listeners run immediately;
     * async listeners are enqueued (same queue as async payload handlers).
     * Post-dispatch hooks run after sync listeners complete and async/queued work has been scheduled.
     */
    public function dispatch(object $event): void
    {
        $this->eventListenerRegistry->ensureBuilt();
        $eventClass = get_class($event);
        $listeners = $this->eventListenerRegistry->getListeners($eventClass);

        // Optional dev-only observer — same shape as RouteExecutor's: absent in
        // production, one has() per dispatch otherwise. Answers the deep-dive
        // questions "which events fired during this request, and which listeners
        // ran on them" that the static event map (ai:ask event) cannot.
        $tracer = $this->resolveTracer();
        $tracer?->mark('event.dispatch', ['event' => $eventClass, 'listeners' => count($listeners)]);

        try {
            foreach ($listeners as $meta) {
                $execution = EventExecution::fromAttributeValue((string) ($meta['execution'] ?? EventExecution::Sync->value));
                match ($execution) {
                    EventExecution::Sync => $this->runListenerSync($meta, $event, $tracer),
                    EventExecution::Async => $this->runListenerDefer($meta, $event),
                    EventExecution::Queued => $this->enqueueListener($meta, $event, $tracer),
                };
            }
        } finally {
            // The event happened whether or not a listener failed: hooks (e.g.
            // the ledger) must still see it. The listener's exception keeps
            // propagating — sync listeners are part of the caller's transaction.
            foreach ($this->postDispatchHooks as $hook) {
                try {
                    $hook($event);
                } catch (\Throwable $e) {
                    StaticLoggerBridge::error('core', 'Post-dispatch hook failed', [
                        'event' => $eventClass,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * The optional dev tracer, wrapped so it can never throw into a dispatch.
     * Resolved per dispatch rather than injected: this dispatcher is built
     * before the tracer's package may have registered, and one has() is the
     * whole production cost.
     */
    private function resolveTracer(): ?\Semitexa\Core\Pipeline\RequestTracerInterface
    {
        // Wrapped whole: get() can throw even after has() said true (a broken
        // binding), and an optional observer failing to RESOLVE must degrade
        // to "no observer", never abort the dispatch it wanted to watch.
        try {
            $container = ContainerFactory::get();
            $resolved = $container->has(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
                ? $container->get(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
                : null;

            return \Semitexa\Core\Pipeline\SafeRequestTracer::wrap(
                $resolved instanceof \Semitexa\Core\Pipeline\RequestTracerInterface ? $resolved : null,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function runListenerSync(array $meta, object $event, ?\Semitexa\Core\Pipeline\RequestTracerInterface $tracer = null): void
    {
        $listener = $this->resolveListener($meta);

        // A span, not a mark: which listener a request's milliseconds went to
        // is exactly the question a slow-event hunt opens the trace to answer.
        $tracer?->begin('event.listener', ['listener' => $meta['class'], 'method' => 'handle', 'event' => get_class($event)]);
        try {
            $listener->handle($event);
        } finally {
            $tracer?->end('event.listener');
        }
    }

    private function resolveListener(array $meta): object
    {
        /** @var \Semitexa\Core\Container\SemitexaContainer $container */
        $container = ContainerFactory::get();

        if ($container->has($meta['class'])) {
            $listener = $container->get($meta['class']);
        } else {
            $listener = $container->resolve($meta['class']);
        }

        if (!method_exists($listener, 'handle')) {
            throw new ConfigurationException(sprintf(
                'Event listener %s must have a handle() method.',
                $meta['class'],
            ));
        }

        return $listener;
    }

    /** Run listener after the current request coroutine finishes. Falls back to sync anywhere else. */
    private function runListenerDefer(array $meta, object $event): void
    {
        // Decided on "inside a request coroutine", not on SAPI: a Swoole HTTP
        // server always runs under the CLI SAPI. Nor merely on "inside a
        // coroutine": Coroutine::defer fires when THAT coroutine exits, and a
        // standing one (the ledger's NATS command loop, a consumer) never
        // does — its listeners would never run and every dispatch would pin
        // one more closure for the life of the worker. Outside a request
        // coroutine (those loops, a child go(), queue worker, console,
        // PHPUnit) run inline, as before, and leave the reactor untouched.
        if (!SwooleBootstrap::isRequestCoroutine()) {
            $this->runListenerSync($meta, $event);
            return;
        }

        // Resolved NOW, while the ExecutionContext is live: Coroutine::defer
        // fires at coroutine exit, after the response is emitted AND after
        // Application has reset request-scoped state, so an #[ExecutionScoped]
        // listener's #[InjectAsMutable] deps must be bound before that.
        $listener = $this->resolveListener($meta);
        $listenerClass = (string) $meta['class'];

        \Swoole\Coroutine::defer(static function () use ($listener, $event, $listenerClass): void {
            // Nothing above a deferred callback can catch: an escaping
            // throwable would be fatal to the worker, not a 500.
            try {
                $listener->handle($event);
            } catch (\Throwable $e) {
                StaticLoggerBridge::error('core', 'Async event listener failed', [
                    'event' => $event::class,
                    'listener' => $listenerClass,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    private function enqueueListener(array $meta, object $event, ?\Semitexa\Core\Pipeline\RequestTracerInterface $tracer = null): void
    {
        $transportName = $meta['transport'] ?? QueueConfig::defaultTransport();
        $queueName = $meta['queue'] ?? QueueConfig::defaultQueueName($meta['event'] ?? 'event');

        $message = new \Semitexa\Core\Queue\Message\QueuedEventListenerMessage(
            listenerClass: $meta['class'],
            eventClass: get_class($event),
            eventPayload: PayloadSerializer::toArray($event),
        );

        $transport = QueueTransportRegistry::create($transportName);
        $transport->publish($queueName, $message->toJson());

        // AFTER publish, deliberately: the mark attests a handoff that
        // happened. Serialization or publish throwing must not leave a trace
        // claiming a message that never reached the queue.
        $tracer?->mark('event.listener.queued', [
            'listener' => $meta['class'],
            'event' => get_class($event),
            'queue' => $queueName,
        ]);
    }

    /**
     * @param array<mixed> $payload
     * @return list<mixed>
     */
    private function buildConstructorArgs(\ReflectionMethod $constructor, array $payload, string $eventClass): array
    {
        $args = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $snakeName = strtolower(preg_replace('/[A-Z]/', '_$0', $name) ?? $name);

            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
                continue;
            }

            if (array_key_exists($snakeName, $payload)) {
                $args[] = $payload[$snakeName];
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'Missing constructor argument "%s" for event class %s.',
                $name,
                $eventClass,
            ));
        }

        return $args;
    }
}
