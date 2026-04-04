<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use ReflectionClass;
use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Discovery\ClassDiscovery;

final class ServerLifecycleRegistry
{
    /** @var array<string, list<array{class: string, phase: string, priority: int, requiresContainer: bool}>> */
    private array $listenersByPhase = [];

    private bool $built = false;

    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {
    }

    /**
     * @return list<array{class: string, phase: string, priority: int, requiresContainer: bool}>
     */
    public function getListeners(string $phase): array
    {
        $this->ensureBuilt();

        return $this->listenersByPhase[$phase] ?? [];
    }

    public function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }

        $classes = $this->classDiscovery->findClassesWithAttribute(AsServerLifecycleListener::class);
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

                $this->listenersByPhase[$phase] ??= [];
                $this->listenersByPhase[$phase][] = $meta;
            } catch (\Throwable) {
                continue;
            }
        }

        foreach ($this->listenersByPhase as $phase => $listeners) {
            usort(
                $this->listenersByPhase[$phase],
                static fn(array $a, array $b): int => $a['priority'] <=> $b['priority'],
            );
        }

        $this->built = true;
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
