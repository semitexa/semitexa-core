<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * A transport whose consume() loop can be told to return: after stop(), it
 * finishes the message in hand and returns instead of taking the next.
 */
interface StoppableQueueTransportInterface extends QueueTransportInterface
{
    public function stop(): void;
}
