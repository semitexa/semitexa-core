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
        $name = strtolower(trim((string) $value));
        $sink = self::tryFrom($name);
        if ($sink === null && $name !== '') {
            // Said, like a rejected LOG_CHANNELS pair: `stdout`, the likely guess
            // in a container, sent everything to var/log/app.log and nothing
            // to the collector, with no word why.
            FallbackErrorLogger::log("LOG_SINK: ignored '{$name}' (it takes file, stderr or both); logging to the file.");
        }

        return $sink ?? self::File;
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
