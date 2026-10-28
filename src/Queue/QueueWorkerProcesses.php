<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * One `queue:work <transport> <queue>` per queue the project's queued
 * listeners and async handlers publish to, and the default queue always.
 * queue:work reads one transport and one queue, and a listener may name its
 * own (the demo's publish to 'async' while the default is 'events'). A
 * process-local transport is left out: no worker can read it.
 */
#[AsService]
#[AsWorkerProcessSource]
final class QueueWorkerProcesses implements WorkerProcessSourceInterface
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected EventListenerRegistry $eventListenerRegistry;

    public function workerProcesses(): array
    {
        $processes = [];
        foreach ($this->queues() as [$transport, $queue]) {
            // One odd name must not take every other queue's worker with it.
            try {
                $processes[] = new WorkerProcess(['queue:work', $transport, $queue], reads: [$transport, $queue]);
            } catch (\InvalidArgumentException $e) {
                StaticLoggerBridge::warning('core', "Queue '{$queue}' on '{$transport}' gets no worker: " . $e->getMessage(), []);
            }
        }

        return $processes;
    }

    /**
     * Every (transport, queue) a worker reads: the default queue, and the
     * queue of each queued listener and handler. A process-local transport is
     * left out: no worker reads it, and nothing outlives the process.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function queues(): array
    {
        $defaultTransport = QueueConfig::defaultTransport();

        /** @var array<string, array{string, string}> $queues */
        $queues = [];
        $add = static function (?string $transport, string $queue) use (&$queues, $defaultTransport): void {
            $transport = $transport ?: $defaultTransport;
            $queues[$transport . ' ' . $queue] = [$transport, $queue];
        };
        $add(null, QueueConfig::defaultQueueName('default'));

        foreach ($this->eventListenerRegistry->queuedListeners() as $meta) {
            $add(self::name($meta['transport']), self::name($meta['queue']) ?? QueueConfig::defaultQueueName($meta['event']));
        }

        $this->attributeDiscovery->initialize();
        $handlers = $this->attributeDiscovery->getHandlerRegistry();
        foreach ($handlers->getHandlerClassNames() as $class) {
            $meta = $handlers->getHandlerByClass($class);
            if ($meta === null || $meta['execution'] !== HandlerExecution::Queued->value) {
                continue;
            }
            $add(self::name($meta['transport']), self::name($meta['queue']) ?? QueueConfig::defaultQueueName($meta['payload'] ?? $class));
        }

        ksort($queues);

        return array_values(array_filter($queues, static fn (array $pair): bool => !QueueConfig::isProcessLocal($pair[0])));
    }

    private static function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
