<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Swoole\Table;

/**
 * Counters in a Swoole\Table created before the workers fork, so every worker
 * of this server counts into the same rows. Table::incr is atomic per row,
 * which is all a sliding-window counter needs.
 *
 * Keys are RateLimiter's `{window}:{index}:{md5}` — at most 63 bytes, the
 * Table's key limit — so the window a row belongs to can be read off its key
 * and sweep() needs no extra column. A full table cannot count: hit() returns
 * null and the limiter lets the request through rather than refusing everyone.
 */
final class SwooleTableRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(private readonly Table $table) {}

    public static function create(int $maxRows): self
    {
        $table = new Table($maxRows);
        $table->column('n', Table::TYPE_INT, 8);
        $table->create();

        return new self($table);
    }

    public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array
    {
        // A full table warns on every incr; that would be a log line per
        // request for as long as it stays full. The doctor check reports it.
        $current = @$this->table->incr($currentKey, 'n', 1);
        if ($current === false) {
            return null;
        }

        $previous = $this->table->get($previousKey, 'n');

        return [(int) $current, is_int($previous) ? $previous : 0];
    }

    public function undo(string $currentKey): void
    {
        $this->table->decr($currentKey, 'n', 1);
    }

    /** Delete counters of windows before the previous one: nothing reads them again. */
    public function sweep(?int $now = null): int
    {
        $now ??= time();
        $stale = [];
        foreach ($this->table as $key => $row) {
            $parts = explode(':', (string) $key, 3);
            if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[0] < 1) {
                continue;
            }
            if ((int) $parts[1] < intdiv($now, (int) $parts[0]) - 1) {
                $stale[] = (string) $key;
            }
        }
        foreach ($stale as $key) {
            $this->table->del($key);
        }

        return count($stale);
    }

    public function count(): int
    {
        return count($this->table);
    }
}
