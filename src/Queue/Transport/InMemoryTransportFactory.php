<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue\Transport;

use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;

class InMemoryTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new InMemoryTransport();
    }
}

