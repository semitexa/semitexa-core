<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * Deletes rotated Swoole log files once they age out.
 *
 * Rotation on its own does not bound anything — Swoole starts a new file and keeps
 * every old one forever, so the disk still fills, just in tidier slices. This is the
 * half that actually stops the growth.
 *
 * It runs in the master process before the server starts, which is the only moment
 * that is guaranteed to happen once per boot rather than once per worker.
 */
final readonly class SwooleLogRetention
{
    /** Never delete anything if the configured window is this or lower. */
    public const int DISABLED = 0;

    public function __construct(
        private string $configuredLogFile,
        private int $keepDays,
    ) {}

    /**
     * @return list<string> paths that were removed (for logging/tests)
     */
    public function prune(?int $now = null): array
    {
        if ($this->keepDays <= self::DISABLED) {
            return [];
        }

        $now ??= time();
        $cutoff = $now - ($this->keepDays * 86400);

        // Only ever touch files this class is responsible for: siblings of the
        // configured log that carry Swoole's numeric date suffix. A bare glob on
        // '.*' would also match things like swoole.log.gz that an operator's own
        // logrotate put there, and deleting those would be a nasty surprise.
        $candidates = glob($this->configuredLogFile . '.*') ?: [];

        $removed = [];
        foreach ($candidates as $path) {
            $suffix = substr($path, strlen($this->configuredLogFile) + 1);
            if (preg_match('/^\d{6,14}$/', $suffix) !== 1) {
                continue;
            }
            if (!is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            // A failed unlink must not take the server down — the point is to free
            // disk, not to make logging a boot dependency.
            if (@unlink($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }
}
