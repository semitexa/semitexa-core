<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;

readonly class ServerConfigurator
{
    public function __construct(private Environment $env) {}

    public function getServerOptions(): array
    {
        return [
            'worker_num'       => $this->env->swooleWorkerNum,
            'max_request'      => $this->env->swooleMaxRequest,
            'enable_coroutine' => true,
            'max_coroutine'    => $this->env->swooleMaxCoroutine,
            'log_file'         => $this->env->swooleLogFile,
            'log_level'        => $this->env->swooleLogLevel,
            'pid_file'         => $this->getPidFilePath(),
            // Async reload: workers get max_wait_time seconds to finish active coroutines,
            // then the manager force-kills any that haven't exited yet.
            // Without this, workers with long-lived connections (SSE, long-poll) never exit
            // during a graceful reload and keep serving stale code indefinitely.
            'reload_async'     => true,
            'max_wait_time'    => 3,
        ];
    }

    public function getHost(): string
    {
        return $this->env->swooleHost;
    }

    public function getPort(): int
    {
        return $this->env->swoolePort;
    }

    private function getPidFilePath(): string
    {
        $dir = ProjectRoot::get() . '/var/run';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/semitexa.pid';
    }
}
