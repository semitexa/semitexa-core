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

        foreach ($listeners as $meta) {
            $execution = EventExecution::fromAttributeValue((string) ($meta['execution'] ?? EventExecution::Sync->value));
            match ($execution) {
                EventExecution::Sync => $this->runListenerSync($meta, $event),
                EventExecution::Async => $this->runListenerDefer($meta, $event),
                EventExecution::Queued => $this->enqueueListener($meta, $event),
            };
        }

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

    private function runListenerSync(array $meta, object $event): void
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
        $listener->handle($event);
    }

    /** Run listener after response is sent (Swoole defer). Falls back to sync if Swoole not available. */
    private function runListenerDefer(array $meta, object $event): void
    {
        // In CLI (queue worker, console command, PHPUnit) there is no Swoole
        // event loop driving deferred callbacks — and queueing callbacks via
        // Swoole\Event::defer leaves the reactor "dirty", which triggers the
        // `swoole_event_rshutdown(): Event::wait() in shutdown function is
        // deprecated` notice at PHP shutdown. Run sync in CLI to keep the
        // reactor untouched.
        $useDefer = PHP_SAPI !== 'cli'
            && extension_loaded('swoole')
            && class_exists(\Swoole\Event::class)
            && method_exists(\Swoole\Event::class, 'defer');

        if ($useDefer) {
            \Swoole\Event::defer(function () use ($meta, $event): void {
                $this->runListenerSync($meta, $event);
            });
        } else {
            $this->runListenerSync($meta, $event);
        }
    }

    private function enqueueListener(array $meta, object $event): void
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
