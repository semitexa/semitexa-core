<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

/** Where application log lines go: LOG_SINK. */
enum LogSink: string
{
    /** LOG_FILE (var/log/app.log), rotated; what logs:app and the Observatory read. */
    case File = 'file';
    /** The process's stderr, one JSON line per entry: what a container runtime collects. */
    case Stderr = 'stderr';
    case Both = 'both';

    public static function fromEnv(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::File;
    }

    public function writesFile(): bool
    {
        return $this !== self::Stderr;
    }

    public function writesStderr(): bool
    {
        return $this !== self::File;
    }
}
