<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * One process the worker container runs: a `bin/semitexa` command, started
 * again $pauseSeconds after it exits. A loop that never returns (queue:work)
 * and a command that does its work and exits (scheduler:plan every 60 s) are
 * the same thing here.
 */
final readonly class WorkerProcess
{
    /** @param list<string> $command the command and its arguments, as after `bin/semitexa` */
    public function __construct(
        public array $command,
        public int $pauseSeconds = 5,
    ) {
        if ($command === []) {
            throw new \InvalidArgumentException('A worker process needs a command.');
        }
        foreach ($command as $word) {
            // The worker container splits the line on spaces: a word has none,
            // and nothing a shell would read as more than a word.
            if (preg_match('/^[A-Za-z0-9._:\/=-]+$/', $word) !== 1) {
                throw new \InvalidArgumentException("A worker process argument must be one plain word, got '{$word}'.");
            }
        }
        if ($pauseSeconds < 0) {
            throw new \InvalidArgumentException('A worker process pause cannot be negative.');
        }
    }

    /** The line `worker:processes` prints: the pause, then the command. */
    public function line(): string
    {
        return $this->pauseSeconds . ' ' . implode(' ', $this->command);
    }
}
