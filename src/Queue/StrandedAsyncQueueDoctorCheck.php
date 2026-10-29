<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Support\DoctorResult;

/**
 * An Async handler used to be queued; it now runs in the process that served
 * the request. Messages queued for it before the upgrade wait on a queue no
 * worker reads any more, and nothing said so.
 */
#[AsDoctorCheck(name: 'queue.stranded-async', package: 'semitexa/core')]
final class StrandedAsyncQueueDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        $container = ContainerFactory::get();
        // Not registered throws; registered as something else is checked below.
        try {
            $discovery = $container->get(AttributeDiscovery::class);
            $inspection = $container->get(QueueInspection::class);
        } catch (NotFoundExceptionInterface) {
            $discovery = $inspection = null;
        }
        if (!$discovery instanceof AttributeDiscovery || !$inspection instanceof QueueInspection) {
            return DoctorResult::warn(
                'Could not check the queues Async handlers used before the upgrade: the container has no attribute discovery or queue inspection.',
                'Run the doctor from a booted project.',
            );
        }
        $discovery->initialize();
        $handlers = $discovery->getHandlerRegistry();
        $read = [];
        foreach ($inspection->targets() as $target) {
            $read[$target['transport'] . ' ' . $target['queue']] = true;
        }

        $stranded = [];
        $unreadable = [];
        $counted = [];
        foreach ($handlers->getHandlerClassNames() as $class) {
            $meta = $handlers->getHandlerByClass($class);
            if ($meta === null || $meta['execution'] !== HandlerExecution::Async->value) {
                continue;
            }
            $transport = is_string($meta['transport'] ?? null) && $meta['transport'] !== '' ? $meta['transport'] : QueueConfig::defaultTransport();
            $queue = is_string($meta['queue'] ?? null) && $meta['queue'] !== '' ? $meta['queue'] : QueueConfig::defaultQueueName($meta['payload'] ?? $class);
            if (QueueConfig::isProcessLocal($transport) || isset($read[$transport . ' ' . $queue]) || !QueueTransportRegistry::has($transport)) {
                continue;
            }
            // One queue, several handlers: counted once, every handler named.
            $key = $transport . ' ' . $queue;
            if (isset($counted[$key])) {
                $counted[$key]['handlers'][] = $class;
                continue;
            }
            try {
                $inspector = QueueTransportRegistry::create($transport);
                $waiting = $inspector instanceof QueueInspectorInterface ? $inspector->depth($queue) : 0;
            } catch (\Throwable $e) {
                // Not counted is not empty.
                $unreadable[] = "'{$queue}' ({$transport}): {$e->getMessage()}";
                continue;
            }
            $counted[$key] = ['waiting' => $waiting, 'queue' => $queue, 'transport' => $transport, 'handlers' => [$class]];
        }
        foreach ($counted as $entry) {
            if ($entry['waiting'] > 0) {
                $stranded[] = "{$entry['waiting']} on '{$entry['queue']}' ({$entry['transport']}), queued for " . implode(', ', $entry['handlers']);
            }
        }

        if ($unreadable !== []) {
            return DoctorResult::warn(
                'Could not count the queues Async handlers used before the upgrade: ' . implode('; ', $unreadable) . ($stranded !== [] ? '. Waiting: ' . implode('; ', $stranded) : '') . '.',
                'Run the doctor again once the transport answers.',
            );
        }
        if ($stranded === []) {
            return DoctorResult::pass('No messages wait on a queue only an Async handler used.');
        }

        return DoctorResult::warn(
            'Messages wait on queues no worker reads: ' . implode('; ', $stranded) . '. These handlers are Async now and run in the request\'s process; what was queued for them before the upgrade will not run.',
            "Run each such queue once with `bin/semitexa queue:work <transport> <queue>` until it is empty, or make the handler Queued (#[AsPayloadHandler(execution: 'queued')]) if it must outlive the request.",
        );
    }
}
