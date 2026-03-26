<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

enum SwooleEvent: string
{
    case WorkerStart = 'WorkerStart';
    case WorkerStop = 'WorkerStop';
    case WorkerError = 'WorkerError';
    case Start = 'Start';
    case Shutdown = 'Shutdown';
    case Request = 'request';
}
