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
}
