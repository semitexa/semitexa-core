<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

interface QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface;
}

