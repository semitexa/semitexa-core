<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

enum ServerLifecyclePhase: string
{
    case PreStart = 'pre_start';
    case WorkerStartBeforeContainer = 'worker_start.before_container';
    case WorkerStartAfterContainer = 'worker_start.after_container';
    case WorkerStartAfterServerBindings = 'worker_start.after_server_bindings';
    case WorkerStartFinalize = 'worker_start.finalize';
    case WorkerStop = 'worker_stop';
    case WorkerError = 'worker_error';
    case Start = 'start';
    case Shutdown = 'shutdown';
}
