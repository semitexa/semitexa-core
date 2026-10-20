<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * What a transport can tell about its queues and dead letters, for
 * `queue:stats`, `queue:failed`, `queue:retry` and `queue:forget`. A transport
 * implements it when it can answer from its own storage; one that cannot (the
 * in-memory transport) does not, and the commands say so.
 *
 * The dead letters are what QueueWorker publishes to `<queue>.failed` once a
 * message has used its retries.
 */
interface QueueInspectorInterface
{
    /** Messages waiting to be taken. */
    public function depth(string $queue): int;

    /** How long the oldest waiting message has waited, in seconds; null when none waits. */
    public function oldestAgeSeconds(string $queue): ?int;

    /** @return list<DeadLetter> newest first */
    public function deadLetters(string $queue, int $limit = 20): array;

    /** Queue the dead letter again (attempts start over) and remove it; false when it is not there. */
    public function retryDeadLetter(string $queue, string $id): bool;

    /** Remove the dead letter for good; false when it is not there. */
    public function forgetDeadLetter(string $queue, string $id): bool;
}
