<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attributes\AsPipelineListener;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use ReflectionClass;

final class PipelineListenerRegistry
{
    /** @var array<string, list<array{class: string, phase: string, priority: int}>> */
    private static array $listenersByPhase = [];

    private static bool $built = false;

    /**
     * @return list<array{class: string, phase: string, priority: int}>
     */
    public static function getListeners(string $phaseClass): array
    {
        self::ensureBuilt();
        return self::$listenersByPhase[$phaseClass] ?? [];
    }

    public static function ensureBuilt(): void
    {
        if (self::$built) {
            return;
        }
        ClassDiscovery::initialize();
        ModuleRegistry::initialize();

        $classes = ClassDiscovery::findClassesWithAttribute(AsPipelineListener::class);
        $filtered = array_filter(
            $classes,
            fn(string $class) => (str_starts_with($class, 'Semitexa\\') && ModuleRegistry::isClassActive($class))
                || self::isProjectPipelineListener($class)
        );

        foreach ($filtered as $className) {
            try {
                $ref = new ReflectionClass($className);
                $attrs = $ref->getAttributes(AsPipelineListener::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var AsPipelineListener $attr */
                $attr = $attrs[0]->newInstance();
                $phase = ltrim($attr->phase, '\\');
                $priority = $attr->priority;
                $meta = [
                    'class' => $className,
                    'phase' => $phase,
                    'priority' => $priority,
                ];
                if (!isset(self::$listenersByPhase[$phase])) {
                    self::$listenersByPhase[$phase] = [];
                }
                self::$listenersByPhase[$phase][] = $meta;
            } catch (\Throwable) {
                continue;
            }
        }

        foreach (self::$listenersByPhase as $phase => $list) {
            usort(self::$listenersByPhase[$phase], fn($a, $b) => $a['priority'] <=> $b['priority']);
        }
        self::$built = true;
    }

    private static function isProjectPipelineListener(string $class): bool
    {
        return str_starts_with($class, 'App\\') && (
            str_contains($class, 'Event\\System\\')
            || str_contains($class, 'Event\\PayloadHandler\\')
        );
    }
}
