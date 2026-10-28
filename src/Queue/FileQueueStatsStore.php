<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * Counters in one JSON file per queue and day under a directory. A writer
 * takes the day's lock file, reads, adds, writes a temp file and renames it
 * over the old one, so a reader (lock-free) sees the old day or the new one,
 * never an empty file. Days older than a week are deleted. Shared only by
 * processes that see the same disk: one host, or var/ mounted into the worker.
 */
final class FileQueueStatsStore implements QueueStatsStoreInterface
{
    private const KEEP_DAYS = 7;

    public function __construct(private readonly string $directory) {}

    public function add(string $queue, int $minute, array $deltas): void
    {
        if ($deltas === []) {
            return;
        }
        // Every failure throws: QueueStatsRecorder says a lost count only when
        // add() does, and a store that returned quietly showed zeros instead.
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Cannot create {$this->directory}");
        }
        $file = $this->file($queue, intdiv($minute, 1440));
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Cannot open {$file}.lock");
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException("Cannot lock {$file}.lock");
        }
        try {
            $data = self::read($file);
            if ($data === null) {
                // Written over, the day's counters were gone for one minute's.
                // Moved aside, the next job starts the day again.
                @rename($file, $file . '.unreadable');
                throw new \RuntimeException("{$file} could not be read; moved aside to {$file}.unreadable");
            }
            foreach ($deltas as $field => $amount) {
                $data[$minute][$field] = (int) ($data[$minute][$field] ?? 0) + $amount;
            }
            $temp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temp, (string) json_encode($data)) === false) {
                @unlink($temp);
                throw new \RuntimeException("Cannot write {$temp}");
            }
            if (!@rename($temp, $file)) {
                @unlink($temp);
                throw new \RuntimeException("Cannot replace {$file}");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->prune($queue, intdiv($minute, 1440));
    }

    public function sum(string $queue, int $fromMinute, int $toMinute): array
    {
        $total = [];
        for ($day = intdiv($fromMinute, 1440); $day <= intdiv($toMinute, 1440); $day++) {
            $file = $this->file($queue, $day);
            $data = self::read($file);
            if ($data === null) {
                // Read as empty, a broken day showed zeros and queue:stats
                // exited 0; QueueStatsReport turns this into counters_error.
                throw new \RuntimeException("{$file} could not be read");
            }
            foreach ($data as $minute => $fields) {
                if ((int) $minute < $fromMinute || (int) $minute > $toMinute || !is_array($fields)) {
                    continue;
                }
                foreach ($fields as $field => $value) {
                    $total[(string) $field] = ($total[(string) $field] ?? 0) + (int) $value;
                }
            }
        }

        return $total;
    }

    public function describe(): string
    {
        return 'file (' . $this->directory . ')';
    }

    /**
     * Empty for a day not written yet, null for a file that cannot be read.
     *
     * @return array<int|string, mixed>|null
     */
    private static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return [];
        }
        $text = @file_get_contents($file);
        $data = $text === false ? null : json_decode($text, true);

        return is_array($data) ? $data : null;
    }

    private function prune(string $queue, int $today): void
    {
        foreach (glob($this->directory . '/' . self::name($queue) . '.*.json') ?: [] as $old) {
            if (preg_match('/\.(\d+)\.json$/', $old, $m) === 1 && (int) $m[1] < $today - self::KEEP_DAYS) {
                @unlink($old);
                @unlink($old . '.lock');
            }
        }
    }

    private function file(string $queue, int $day): string
    {
        return $this->directory . '/' . self::name($queue) . '.' . $day . '.json';
    }

    /** One file per queue: `a.b` and `a_b` both became `a_b`, and shared one. */
    private static function name(string $queue): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $queue);

        return $safe === $queue ? $safe : $safe . '-' . substr(hash('sha256', $queue), 0, 8);
    }
}
