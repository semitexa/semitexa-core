<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * A transport that can hold a message back by itself.
 *
 * The worker retries a failed job `retryDelay` seconds later. It used to wait
 * those seconds inside the consume loop, so one failing job stalled every job
 * behind it, and a stop during the wait lost the retry. A transport that
 * implements this keeps the waiting message on its own side; the worker
 * publishes it and moves on.
 */
interface DelayedQueueTransportInterface extends QueueTransportInterface
{
    /** Publish a message no consumer receives before $delaySeconds have passed. */
    public function publishDelayed(string $queueName, string $payload, int $delaySeconds): void;
}
