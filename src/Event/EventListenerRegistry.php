<?php

declare(strict_types=1);

namespace Semitexa\Core\Event;

use Semitexa\Core\Attributes\AsEventListener;
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
    private static array $listenersByEvent = [];

    private static bool $built = false;

    /**
     * @return array<array{class: string, event: string, execution: string, transport: mixed, queue: mixed, priority: int}>
     */
    public static function getListeners(string $eventClass): array
    {
        self::ensureBuilt();
        return self::$listenersByEvent[$eventClass] ?? [];
    }

    public static function ensureBuilt(): void
    {
        if (self::$built) {
            return;
        }
        ClassDiscovery::initialize();
        ModuleRegistry::initialize();

        $classes = ClassDiscovery::findClassesWithAttribute(AsEventListener::class);
        $filtered = array_filter(
            $classes,
            fn(string $class) => (str_starts_with($class, 'Semitexa\\') && self::isModuleActiveForClass($class))
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
                if (!isset(self::$listenersByEvent[$eventClass])) {
                    self::$listenersByEvent[$eventClass] = [];
                }
                self::$listenersByEvent[$eventClass][] = $meta;
            } catch (\Throwable $e) {
                continue;
            }
        }

        foreach (self::$listenersByEvent as $eventClass => $list) {
            usort(self::$listenersByEvent[$eventClass], fn($a, $b) => ($a['priority'] <=> $b['priority']));
        }
        self::$built = true;
    }

    private static function isModuleActiveForClass(string $class): bool
    {
        return ModuleRegistry::getModuleNameForClass($class) !== null;
    }

    private static function isProjectEventListeners(string $class): bool
    {
        return str_starts_with($class, 'App\\') && str_contains($class, 'Handler\\Event\\');
    }

    /** Execution from listener attribute; default Sync when not set */
    private static function resolveExecution(EventExecution|string|null $listenerOverride): EventExecution
    {
        if ($listenerOverride !== null && $listenerOverride !== '') {
            return EventExecution::normalize($listenerOverride);
        }
        return EventExecution::Sync;
    }
}
