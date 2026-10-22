<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Discovery\ClassDiscovery;

/**
 * The queues the `queue:*` commands look at, and each one's inspector: every
 * (transport, queue) a worker reads, narrowed by --transport / --queue. A
 * transport that cannot inspect itself comes back with no inspector.
 */
#[AsService]
final class QueueInspection
{
    #[InjectAsReadonly]
    protected QueueWorkerProcesses $workers;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** @return list<array{transport: string, queue: string, inspector: ?QueueInspectorInterface}> */
    public function targets(?string $transport = null, ?string $queue = null): array
    {
        // A NATS wildcard or a blank token would read other queues' dead
        // letters under its own name: `--queue='*'` listed every queue's.
        if ($queue !== null && preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $queue) !== 1) {
            throw new \InvalidArgumentException("Not a queue name: '{$queue}' (letters, digits, '-', '_', dot-separated).");
        }
        $known = $this->allQueues();
        if ($queue !== null) {
            // The transport the queue runs on, when --transport did not say:
            // a listener may queue on another than the default.
            $on = array_values(array_filter($known, static fn (array $p): bool => $p[1] === $queue && ($transport === null || $p[0] === $transport)));
            $pairs = $on !== [] ? $on : [[$transport ?? QueueConfig::defaultTransport(), $queue]];
        } else {
            $pairs = array_values(array_filter($known, static fn (array $p): bool => $transport === null || $p[0] === $transport));
        }

        $targets = [];
        foreach ($pairs as [$name, $q]) {
            $instance = QueueTransportRegistry::has($name) ? QueueTransportRegistry::create($name) : null;
            $targets[] = [
                'transport' => $name,
                'queue' => $q,
                'inspector' => $instance instanceof QueueInspectorInterface ? $instance : null,
            ];
        }

        return $targets;
    }

    /**
     * The queue:work queues, and every other queue a package's own worker
     * reads (mail:work, media:work): those were missing from queue:stats and
     * the Queues app though their workers count jobs too. A source that fails
     * leaves its queue out; worker:processes is where that failure is said.
     *
     * @return list<array{string, string}>
     */
    private function allQueues(): array
    {
        $pairs = [];
        foreach ($this->workers->queues() as $pair) {
            $pairs[$pair[0] . ' ' . $pair[1]] = $pair;
        }
        if (!isset($this->classDiscovery, $this->container)) {
            return array_values($pairs);
        }

        try {
            $sources = $this->classDiscovery->findClassesWithAttribute(AsWorkerProcessSource::class);
        } catch (\Throwable) {
            return array_values($pairs);
        }
        foreach ($sources as $class) {
            if ($class === QueueWorkerProcesses::class) {
                continue;
            }
            try {
                $source = $this->container->get($class);
                if (!$source instanceof WorkerProcessSourceInterface) {
                    continue;
                }
                foreach ($source->workerProcesses() as $process) {
                    if ($process->reads !== null) {
                        $pairs[$process->reads[0] . ' ' . $process->reads[1]] ??= $process->reads;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }
        ksort($pairs);

        return array_values($pairs);
    }
}
