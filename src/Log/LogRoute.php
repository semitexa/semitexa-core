<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

/** Where the entries of one log channel go: a file, stderr, both, or nowhere. */
final readonly class LogRoute
{
    /** @param string|null $file project-relative path; null writes no file */
    public function __construct(
        public ?string $file,
        public bool $stderr,
    ) {}

    public function writesNothing(): bool
    {
        return $this->file === null && !$this->stderr;
    }

    public static function forSink(LogSink $sink, string $logFile): self
    {
        return new self($sink->writesFile() ? $logFile : null, $sink->writesStderr());
    }
}
