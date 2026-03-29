<?php

declare(strict_types=1);

namespace Semitexa\Core\Event;

use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Support\DtoSerializer;

/**
 * Single entry point for events: create() builds the event instance (framework-controlled),
 * dispatch() runs all listeners (sync or async via the same queue as payload handlers).
 *
 * @internal Uses ContainerFactory::get() — this is core framework plumbing, not application code.
 */
#[SatisfiesServiceContract(of: EventDispatcherInterface::class)]
final class EventDispatcher implements EventDispatcherInterface
{
    /**
     * Create an event instance. Use this instead of "new Event()" so the framework
     * can apply initialization, validation, or optimizations now or later.
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
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \InvalidArgumentException("Event class must have a zero-argument constructor: {$eventClass}");
        }

        $instance = $reflection->newInstance();
        return DtoSerializer::hydrate($instance, $payload);
    }

    /**
     * Dispatch event to all registered listeners. Sync listeners run immediately;
     * async listeners are enqueued (same queue as async payload handlers).
     */
    public function dispatch(object $event): void
    {
        EventListenerRegistry::ensureBuilt();
        $eventClass = get_class($event);
        $listeners = EventListenerRegistry::getListeners($eventClass);

        foreach ($listeners as $meta) {
            $execution = EventExecution::fromAttributeValue((string) ($meta['execution'] ?? EventExecution::Sync->value));
            match ($execution) {
                EventExecution::Sync => $this->runListenerSync($meta, $event),
                EventExecution::Async => $this->runListenerDefer($meta, $event),
                EventExecution::Queued => $this->enqueueListener($meta, $event),
            };
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
            throw new \LogicException(sprintf(
                'Event listener %s must have a handle() method.',
                $meta['class'],
            ));
        }
        $listener->handle($event);
    }

    /** Run listener after response is sent (Swoole defer). Falls back to sync if Swoole not available. */
    private function runListenerDefer(array $meta, object $event): void
    {
        if (extension_loaded('swoole') && class_exists(\Swoole\Event::class) && method_exists(\Swoole\Event::class, 'defer')) {
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
            eventPayload: DtoSerializer::toArray($event),
        );

        $transport = QueueTransportRegistry::create($transportName);
        $transport->publish($queueName, $message->toJson());
    }
}
