<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

readonly class ServerConfigurator
{
    /**
     * How long a leaving worker may take before the manager kills it, unless
     * SWOOLE_MAX_WAIT_TIME says otherwise. Its requests in flight get a second
     * less (WorkerExitDrainPolicy). It was 3: a request longer than 2 s was
     * cancelled at every recycle and answered 500 (13 times on one route in a
     * 50 h soak). Streams are cut at once whatever this is, so only real
     * requests hold a leaving worker this long.
     */
    public const MAX_WAIT_SECONDS = 10;

    /** SWOOLE_MAX_WAIT_TIME in whole seconds, 2 to 300; MAX_WAIT_SECONDS when unset or not a number. */
    public static function maxWaitSeconds(): int
    {
        $value = trim((string) Environment::getEnvValue('SWOOLE_MAX_WAIT_TIME', ''));

        return ctype_digit($value) ? max(2, min(300, (int) $value)) : self::MAX_WAIT_SECONDS;
    }

    public function __construct(private Environment $env) {}

    public function getServerOptions(): array
    {
        return [
            'worker_num'       => $this->env->swooleWorkerNum,
            'max_request'      => $this->env->swooleMaxRequest,
            'enable_coroutine' => true,
            'max_coroutine'    => $this->env->swooleMaxCoroutine,
            // The largest request accepted; a bigger one is refused before any
            // handler runs. Uploads ride a request, so this caps them too.
            'package_max_length' => $this->env->swoolePackageMaxLength,
            // Absolute, and the same path retention scans. Handing Swoole the raw
            // relative value meant that whenever the process CWD was not the project
            // root, rotated logs were written to one directory and pruned in another.
            'log_file'         => $this->logFilePath(),
            'log_level'        => $this->env->swooleLogLevel,
            // Without this Swoole appends to one file forever. Note the side effect:
            // once rotation is on, Swoole writes to '<log_file>.<date>' and leaves
            // log_file itself empty, so readers must resolve the active file.
            'log_rotation'     => $this->logRotation()->swooleValue(),
            'pid_file'         => $this->getPidFilePath(),
            // Async reload: workers get max_wait_time seconds to finish active coroutines,
            // then the manager force-kills any that haven't exited yet.
            // Without this, workers with long-lived connections (SSE, long-poll) never exit
            // during a graceful reload and keep serving stale code indefinitely.
            'reload_async'     => true,
            'max_wait_time'    => self::maxWaitSeconds(),
        ];
    }

    public function logRotation(): SwooleLogRotation
    {
        return SwooleLogRotation::fromEnv($this->env->swooleLogRotation);
    }

    /**
     * Absolute path Swoole is configured to log to. `swooleLogFile` is relative in
     * every shipped config, and retention has to glob a real directory.
     */
    public function logFilePath(): string
    {
        $configured = $this->env->swooleLogFile;

        return str_starts_with($configured, '/')
            ? $configured
            : ProjectRoot::get() . '/' . ltrim($configured, '/');
    }

    public function logRetention(): SwooleLogRetention
    {
        return new SwooleLogRetention($this->logFilePath(), $this->env->swooleLogRetentionDays);
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
