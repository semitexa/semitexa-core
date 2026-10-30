<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Log\StaticLoggerBridge;
use Swoole\Table;

/**
 * Counters in a Swoole\Table created before the workers fork, so every worker
 * of this server counts into the same rows. Table::incr is atomic per row,
 * which is all a sliding-window counter needs.
 *
 * Keys are RateLimiter's `{window}:{index}:{md5}` — at most 63 bytes, the
 * Table's key limit — so the window a row belongs to can be read off its key
 * and sweep() needs no extra column.
 *
 * A full table swept itself only once a minute, and until then hit() said it
 * could not count, which the limiter takes as "let it through": anyone able
 * to send ~50 000 distinct subjects (random emails to a reset form, a /48 of
 * IPv6 addresses) switched every limit on the server off. A full table now
 * sweeps (at most every five seconds per worker) and, if that frees nothing,
 * counts in this worker instead, a subject staying there for its window: the
 * limit then holds per worker, weaker than shared. Only past the worker's own
 * InMemoryRateLimitStore::MAX_COUNTERS is a request let through uncounted.
 * That is said in the log, once a minute.
 */
final class SwooleTableRateLimitStore implements RateLimitStoreInterface
{
    private const FULL_REPORT_EVERY_SECONDS = 60;

    private const SWEEP_ON_FULL_EVERY_SECONDS = 5;

    private ?InMemoryRateLimitStore $overflow = null;

    private int $sweptOnFullAt = PHP_INT_MIN;

    private int $reportedFullAt = PHP_INT_MIN;

    public function __construct(
        private readonly Table $table,
        private readonly ?\Closure $clock = null,
    ) {}

    public static function create(int $maxRows, ?\Closure $clock = null): self
    {
        $table = new Table($maxRows);
        $table->column('n', Table::TYPE_INT, 8);
        $table->create();

        return new self($table, $clock);
    }

    public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array
    {
        // A subject already counting in the overflow stays there for the
        // window: moved back to the table once a sweep freed rows, its count
        // started again at 1, and keeping the table near full reset it at will.
        if ($this->overflow?->holds($currentKey) === true) {
            return $this->withTablePrevious($this->overflow->hit($currentKey, $previousKey, $ttlSeconds), $previousKey);
        }

        // A full table warns on every incr; that would be a log line per
        // request for as long as it stays full. full() reports it instead.
        // The stubs say int; a full table answers false.
        /** @var int|false $current */
        $current = @$this->table->incr($currentKey, 'n', 1);
        if ($current === false) {
            $now = $this->now();
            // A sweep that frees nothing costs ~30 ms on a full table: once in
            // a while per worker, not on every request while it stays full.
            if ($now - $this->sweptOnFullAt >= self::SWEEP_ON_FULL_EVERY_SECONDS) {
                $this->sweptOnFullAt = $now;
                $this->sweep($now);
                /** @var int|false $current */
                $current = @$this->table->incr($currentKey, 'n', 1);
            }
            if ($current === false) {
                return $this->withTablePrevious($this->full($now)->hit($currentKey, $previousKey, $ttlSeconds), $previousKey);
            }
        }

        $previous = $this->table->get($previousKey, 'n');

        return [(int) $current, max(is_int($previous) ? $previous : 0, $this->overflow?->counted($previousKey) ?? 0)];
    }

    public function undo(string $currentKey): void
    {
        // The overflow first: another worker may have put the key into the
        // table since, and the decrement belongs where the hit was counted.
        if ($this->overflow?->holds($currentKey) === true) {
            $this->overflow->undo($currentKey);

            return;
        }
        // A key not in the table is not added by a decrement.
        if ($this->table->exist($currentKey)) {
            $this->table->decr($currentKey, 'n', 1);
        }
    }

    /**
     * The previous window from wherever it was counted: the table, while this
     * window counts in the overflow, held a count the overflow knew nothing of.
     *
     * @param array{0: int, 1: int}|null $counts
     * @return array{0: int, 1: int}|null
     */
    private function withTablePrevious(?array $counts, string $previousKey): ?array
    {
        if ($counts === null) {
            return null;
        }
        $previous = $this->table->get($previousKey, 'n');

        return [$counts[0], max($counts[1], is_int($previous) ? $previous : 0)];
    }

    private function full(int $now): InMemoryRateLimitStore
    {
        if ($now - $this->reportedFullAt >= self::FULL_REPORT_EVERY_SECONDS) {
            $this->reportedFullAt = $now;
            StaticLoggerBridge::warning('rate_limit', 'The rate-limit table is full: limits count per worker until it frees up. Raise RATE_LIMIT_TABLE_SIZE, or set RATE_LIMIT_STORE=redis.', [
                'rows' => count($this->table),
                'size' => $this->table->getSize(),
            ]);
        }

        return $this->overflow ??= new InMemoryRateLimitStore($this->clock);
    }

    private function now(): int
    {
        $now = $this->clock !== null ? ($this->clock)() : null;

        return is_numeric($now) ? (int) $now : time();
    }

    /** Delete counters of windows before the previous one: nothing reads them again. */
    public function sweep(?int $now = null): int
    {
        $now ??= time();
        $stale = [];
        foreach ($this->table as $key => $row) {
            if (!is_string($key)) {
                continue;
            }
            $parts = explode(':', $key, 3);
            if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[0] < 1) {
                continue;
            }
            if ((int) $parts[1] < intdiv($now, (int) $parts[0]) - 1) {
                $stale[] = $key;
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
