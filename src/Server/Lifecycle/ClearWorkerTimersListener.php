<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;

/**
 * First thing on worker stop: silence every registered background timer
 * ({@see WorkerTimerRegistry}) so no new tick fires into a worker that is
 * tearing down — the window in which a tick's DB read can deadlock the last
 * coroutine (see the registry docblock for the observed FATAL).
 *
 * priority -100 → runs before other WorkerStop listeners (ascending order),
 * so nothing re-enters timer work after they clean up. requiresContainer:
 * false — this must run even when container teardown is already in motion,
 * and it needs nothing from it.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStop->value,
    priority: -100,
    requiresContainer: false,
)]
final class ClearWorkerTimersListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        WorkerTimerRegistry::clearAll();
    }
}
