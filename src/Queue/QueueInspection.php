<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

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

    /** @return list<array{transport: string, queue: string, inspector: ?QueueInspectorInterface}> */
    public function targets(?string $transport = null, ?string $queue = null): array
    {
        $known = $this->workers->queues();
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
}
