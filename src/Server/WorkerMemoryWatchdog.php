<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * When a worker should leave because of its memory.
 *
 * SWOOLE_MAX_REQUEST recycles a worker after N finished requests, which does
 * nothing for the worker that stops finishing them. The portal outage of
 * August 2026 was that worker: wedged on a blocking Redis read, its memory
 * climbed until PHP killed it at the 128M limit — mid-request, with every
 * stream it held cut off. A worker that watches its own memory can leave
 * before that, through the same drain a reload uses.
 *
 * Measured against memory_get_usage(true), the memory PHP has taken from the
 * system, which is what the memory_limit check counts.
 */
final readonly class WorkerMemoryWatchdog
{
    private function __construct(
        public int $limitBytes,
        public int $thresholdBytes,
    ) {
    }

    /**
     * Null when there is nothing sensible to watch: no limit (-1 or
     * unreadable), or a ratio outside (0, 1) — at the limit PHP has already
     * killed the worker, and 0 is how the watchdog is turned off.
     */
    public static function fromLimit(string $memoryLimit, float $ratio): ?self
    {
        $limit = self::parseBytes($memoryLimit);
        if ($limit === null || $ratio <= 0.0 || $ratio >= 1.0) {
            return null;
        }

        return new self($limit, (int) floor($limit * $ratio));
    }

    public function isOver(int $usageBytes): bool
    {
        return $usageBytes > $this->thresholdBytes;
    }

    /**
     * A memory_limit value in bytes, as PHP reads it: an integer with an
     * optional K, M or G. Null for -1 (unlimited) and anything unreadable.
     */
    public static function parseBytes(string $value): ?int
    {
        if (preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $value, $m) !== 1) {
            return null;
        }
        $multiplier = match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
        // Past PHP_INT_MAX the digits cast to a clamped int and the product
        // turns float: either way the value is not a limit PHP could hold.
        if (strlen(ltrim($m[1], '0')) > 19 || (int) $m[1] > intdiv(PHP_INT_MAX, $multiplier)) {
            return null;
        }
        $bytes = (int) $m[1] * $multiplier;

        return $bytes > 0 ? $bytes : null;
    }
}
