<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue\Fixtures;

use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;

final class RecordingQueueTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new RecordingQueueTransport();
    }
}
