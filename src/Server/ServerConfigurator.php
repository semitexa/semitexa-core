<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Environment;

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
        $dir = dirname(__DIR__, 5) . '/var/run';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/semitexa.pid';
    }
}
