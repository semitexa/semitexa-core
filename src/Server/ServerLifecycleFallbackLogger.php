<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

final class ServerLifecycleFallbackLogger
{
    public static function logWorkerError(
        int $workerId,
        int $workerPid,
        int $exitCode,
        int $signal,
    ): void {
        error_log(sprintf(
            '[Semitexa] lifecycle=worker_error worker_id=%d worker_pid=%d exit_code=%d signal=%d',
            $workerId,
            $workerPid,
            $exitCode,
            $signal,
        ));
    }

    /**
     * The line an operator needs when a worker has stopped being a worker and become a
     * loop. Deliberately separate from the per-crash line: that one is indistinguishable
     * from an ordinary one-off crash, which is precisely why the loop stayed invisible.
     */
    public static function logWorkerCrashLoop(
        int $workerId,
        int $crashes,
        int $windowSeconds,
    ): void {
        error_log(sprintf(
            '[Semitexa] lifecycle=worker_crash_loop worker_id=%d crashes=%d window_s=%d '
                . '— this worker keeps dying at boot and being respawned; optional boot-path '
                . 'work is standing down. Check what runs at worker start.',
            $workerId,
            $crashes,
            $windowSeconds,
        ));
    }
}
