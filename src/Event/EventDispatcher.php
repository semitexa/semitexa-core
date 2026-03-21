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
        $instance = new $eventClass();
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
            $execution = EventExecution::tryFrom($meta['execution'] ?? 'sync') ?? EventExecution::Sync;
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
        try {
            if ($container->has($meta['class'])) {
                $listener = $container->get($meta['class']);
            } else {
                $listener = $container->resolve($meta['class']);
            }
        } catch (\Throwable $e) {
            // Listener has unresolvable dependencies (e.g. interface not yet implemented) — skip gracefully
            if (function_exists('error_log')) {
                error_log(sprintf(
                    'Semitexa EventDispatcher: skipping listener "%s" — %s',
                    $meta['class'],
                    $e->getMessage()
                ));
            }
            return;
        }
        if (!method_exists($listener, 'handle')) {
            return;
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

        try {
            $transport = QueueTransportRegistry::create($transportName);
            $transport->publish($queueName, $message->toJson());
        } catch (\Throwable $e) {
            // Queue unavailable (e.g. RabbitMQ not running): run listener synchronously so the request still succeeds
            if (function_exists('error_log')) {
                error_log(sprintf(
                    'Semitexa EventDispatcher: queue "%s" unavailable (%s). Running listener "%s" synchronously.',
                    $transportName,
                    $e->getMessage(),
                    $meta['class'] ?? 'unknown'
                ));
            }
            $this->runListenerSync($meta, $event);
        }
    }
}
