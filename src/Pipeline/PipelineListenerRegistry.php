<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use ReflectionClass;

final class PipelineListenerRegistry
{
    /** @var array<string, list<array{class: string, phase: string, priority: int}>> */
    private array $listenersByPhase = [];

    private bool $built = false;

    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
        private readonly ModuleRegistry $moduleRegistry,
    ) {}

    /**
     * @return list<array{class: string, phase: string, priority: int}>
     */
    public function getListeners(string $phaseClass): array
    {
        $this->ensureBuilt();
        return $this->listenersByPhase[$phaseClass] ?? [];
    }

    public function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }
        $this->classDiscovery->initialize();
        $this->moduleRegistry->initialize();

        $classes = $this->classDiscovery->findClassesWithAttribute(AsPipelineListener::class);
        $filtered = array_filter(
            $classes,
            fn(string $class) => (str_starts_with($class, 'Semitexa\\') && $this->moduleRegistry->isClassActive($class))
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
                if (!isset($this->listenersByPhase[$phase])) {
                    $this->listenersByPhase[$phase] = [];
                }
                $this->listenersByPhase[$phase][] = $meta;
            } catch (\Throwable) {
                continue;
            }
        }

        foreach ($this->listenersByPhase as $phase => $list) {
            usort($this->listenersByPhase[$phase], fn($a, $b) => $a['priority'] <=> $b['priority']);
        }
        $this->built = true;
    }

    private static function isProjectPipelineListener(string $class): bool
    {
        return str_starts_with($class, 'App\\') && (
            str_contains($class, 'Event\\System\\')
            || str_contains($class, 'Event\\PayloadHandler\\')
        );
    }
}
