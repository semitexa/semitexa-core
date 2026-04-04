<?php

declare(strict_types=1);

namespace Semitexa\Core\Event;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Config\EnvValueResolver;
use ReflectionClass;

/**
 * Discovers event listeners from #[AsEventListener(event: EventClass::class)] and indexes by event class.
 */
final class EventListenerRegistry
{
    /** @var array<string, array<array{class: string, event: string, execution: string, transport: mixed, queue: mixed, priority: int}>> */
    private array $listenersByEvent = [];

    private bool $built = false;

    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
        private readonly ModuleRegistry $moduleRegistry,
    ) {}

    /**
     * @return array<array{class: string, event: string, execution: string, transport: mixed, queue: mixed, priority: int}>
     */
    public function getListeners(string $eventClass): array
    {
        $this->ensureBuilt();
        return $this->listenersByEvent[$eventClass] ?? [];
    }

    public function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }
        $this->classDiscovery->initialize();
        $this->moduleRegistry->initialize();

        $classes = $this->classDiscovery->findClassesWithAttribute(AsEventListener::class);
        $filtered = array_filter(
            $classes,
            fn(string $class) => (str_starts_with($class, 'Semitexa\\') && $this->moduleRegistry->isClassActive($class))
                || self::isProjectEventListeners($class)
        );

        foreach ($filtered as $className) {
            try {
                $ref = new ReflectionClass($className);
                $attrs = $ref->getAttributes(AsEventListener::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var AsEventListener $attr */
                $attr = $attrs[0]->newInstance();
                $eventClass = ltrim($attr->event, '\\');
                $execution = self::resolveExecution($attr->execution);
                $transport = $attr->transport !== null ? EnvValueResolver::resolve($attr->transport) : null;
                $queue = $attr->queue !== null ? EnvValueResolver::resolve($attr->queue) : null;
                $priority = $attr->priority ?? 0;
                $meta = [
                    'class' => $className,
                    'event' => $eventClass,
                    'execution' => $execution->value,
                    'transport' => $transport ?: null,
                    'queue' => $queue ?: null,
                    'priority' => $priority,
                ];
                if (!isset($this->listenersByEvent[$eventClass])) {
                    $this->listenersByEvent[$eventClass] = [];
                }
                $this->listenersByEvent[$eventClass][] = $meta;
            } catch (\Throwable $e) {
                continue;
            }
        }

        foreach ($this->listenersByEvent as $eventClass => $list) {
            usort($this->listenersByEvent[$eventClass], fn($a, $b) => ($a['priority'] <=> $b['priority']));
        }
        $this->built = true;
    }

    /**
     * @return string[] All discovered listener class names (across all events).
     */
    public function getAllListenerClasses(): array
    {
        $this->ensureBuilt();
        $classes = [];
        foreach ($this->listenersByEvent as $listeners) {
            foreach ($listeners as $meta) {
                $classes[$meta['class']] = true;
            }
        }
        return array_keys($classes);
    }

    private static function isProjectEventListeners(string $class): bool
    {
        return str_starts_with($class, 'App\\') && (
            str_contains($class, 'Event\\DomainListener\\')
        );
    }

    private static function resolveExecution(EventExecution $listenerExecution): EventExecution
    {
        return $listenerExecution;
    }
}
