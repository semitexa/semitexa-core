<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

/**
 * When a listener runs.
 *
 * Everything below `ConsoleStartAfterContainer` belongs to a Swoole worker.
 * That last one does not: a CLI process builds the same container and then runs
 * one command, and some wiring is needed in both worlds — a listener that only
 * fires in a worker leaves the console with a half-configured runtime, which is
 * exactly how a skill run from a terminal came to write a row that no
 * subscriber ever heard about.
 */
enum ServerLifecyclePhase: string
{
    case PreStart = 'pre_start';
    case WorkerStartBeforeContainer = 'worker_start.before_container';
    case WorkerStartAfterContainer = 'worker_start.after_container';
    case WorkerStartAfterServerBindings = 'worker_start.after_server_bindings';
    case WorkerStartFinalize = 'worker_start.finalize';
    case WorkerStop = 'worker_stop';
    case WorkerExit = 'worker_exit';
    case WorkerError = 'worker_error';
    case Start = 'start';
    case Shutdown = 'shutdown';

    /**
     * A console command is about to run and the container is built.
     *
     * Deliberately its own phase rather than reusing WorkerStartAfterContainer:
     * listeners written for a worker start timers, bind servers and open long
     * lived channels, none of which a one-shot CLI process should inherit.
     */
    case ConsoleStartAfterContainer = 'console_start.after_container';
}
