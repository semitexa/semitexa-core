<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;

/**
 * The misconfiguration this catches used to surface as a worker crash at
 * first dispatch: EVENTS_ASYNC=1 with no NATS transport installed.
 */
#[AsDoctorCheck(name: 'queue.transport', package: 'semitexa/core')]
final class QueueTransportDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        QueueTransportRegistry::initialize();

        try {
            $transport = QueueConfig::defaultTransport();
        } catch (\Throwable $e) {
            return DoctorResult::fail(
                $e->getMessage(),
                'Install semitexa-ledger + a reachable NATS, or set EVENTS_ASYNC=0. '
                . 'Broker-less variant generation stays available via media:drain / media:import --sync.',
            );
        }

        if (!QueueTransportRegistry::has($transport)) {
            return DoctorResult::fail(
                "Configured queue transport '{$transport}' is not registered.",
                'Check EVENTS_TRANSPORT / EVENTS_ASYNC and the package providing the transport.',
            );
        }

        if ($transport === 'in-memory' || $transport === 'memory') {
            return DoctorResult::pass(
                "Transport 'in-memory' (sync, per-process). Cross-process workers like media:work "
                . 'will not receive messages — use DB-drain commands where available.',
            );
        }

        return DoctorResult::pass("Queue transport '{$transport}' registered.");
    }
}
