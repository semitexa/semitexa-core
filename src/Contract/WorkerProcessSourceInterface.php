<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Queue\WorkerProcess;

/**
 * The processes a package needs running for its queued or scheduled work to
 * happen: a queue consumer, a scheduler loop, an outbox poller. Return none
 * when there is nothing to run (for example when its queue is in-memory).
 */
interface WorkerProcessSourceInterface
{
    /** @return list<WorkerProcess> */
    public function workerProcesses(): array;
}
