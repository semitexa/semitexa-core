<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use ReflectionClass;
use Semitexa\Core\Attributes\AsServerLifecycleListener;
use Semitexa\Core\Discovery\ClassDiscovery;

final class ServerLifecycleRegistry
{
    /** @var array<string, list<array{class: string, phase: string, priority: int, requiresContainer: bool}>> */
    private static array $listenersByPhase = [];

    private static bool $built = false;

    /**
     * @return list<array{class: string, phase: string, priority: int, requiresContainer: bool}>
     */
    public static function getListeners(string $phase): array
    {
        self::ensureBuilt();

        return self::$listenersByPhase[$phase] ?? [];
    }

    public static function ensureBuilt(): void
    {
        if (self::$built) {
            return;
        }

        ClassDiscovery::initialize();
        $classes = ClassDiscovery::findClassesWithAttribute(AsServerLifecycleListener::class);
        $filtered = array_filter(
            $classes,
            static fn(string $class): bool => str_starts_with($class, 'Semitexa\\')
                || self::isProjectLifecycleListener($class),
        );

        foreach ($filtered as $className) {
            try {
                $ref = new ReflectionClass($className);
                $attrs = $ref->getAttributes(AsServerLifecycleListener::class);
                if ($attrs === []) {
                    continue;
                }

                /** @var AsServerLifecycleListener $attr */
                $attr = $attrs[0]->newInstance();
                $phase = self::normalizePhase($attr->phase);
                $meta = [
                    'class' => $className,
                    'phase' => $phase,
                    'priority' => $attr->priority,
                    'requiresContainer' => $attr->requiresContainer,
                ];

                self::$listenersByPhase[$phase] ??= [];
                self::$listenersByPhase[$phase][] = $meta;
            } catch (\Throwable) {
                continue;
            }
        }

        foreach (self::$listenersByPhase as $phase => $listeners) {
            usort(
                self::$listenersByPhase[$phase],
                static fn(array $a, array $b): int => $a['priority'] <=> $b['priority'],
            );
        }

        self::$built = true;
    }

    private static function normalizePhase(string $phase): string
    {
        return ServerLifecyclePhase::tryFrom($phase)?->value ?? $phase;
    }

    private static function isProjectLifecycleListener(string $class): bool
    {
        return str_starts_with($class, 'App\\');
    }
}
