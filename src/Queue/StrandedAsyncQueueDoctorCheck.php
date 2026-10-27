<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

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
        $discovery = $container->get(AttributeDiscovery::class);
        $discovery->initialize();
        $handlers = $discovery->getHandlerRegistry();
        $read = [];
        foreach ($container->get(QueueInspection::class)->targets() as $target) {
            $read[$target['transport'] . ' ' . $target['queue']] = true;
        }

        $stranded = [];
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
            try {
                $inspector = QueueTransportRegistry::create($transport);
                $waiting = $inspector instanceof QueueInspectorInterface ? $inspector->depth($queue) : 0;
            } catch (\Throwable) {
                continue;
            }
            if ($waiting > 0) {
                $stranded[] = "{$waiting} on '{$queue}' ({$transport}), queued for {$class}";
            }
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
