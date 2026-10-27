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
        @mkdir($this->directory, 0777, true);
        $file = $this->file($queue, intdiv($minute, 1440));
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return;
        }
        try {
            $data = self::read($file);
            foreach ($deltas as $field => $amount) {
                $data[$minute][$field] = (int) ($data[$minute][$field] ?? 0) + $amount;
            }
            $temp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temp, (string) json_encode($data)) !== false) {
                rename($temp, $file);
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
            foreach (self::read($this->file($queue, $day)) as $minute => $fields) {
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

    /** @return array<int|string, mixed> */
    private static function read(string $file): array
    {
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
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
