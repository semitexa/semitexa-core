<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

use Semitexa\Core\Server\SwooleLogRetention;

/**
 * Bounds the application log by size.
 *
 * The framework already rotates and prunes Swoole's log — see {@see SwooleLogRotation}
 * and {@see SwooleLogRetention} — and never touched the application's own. Measured on
 * the dev host: `swoole.log`, the file with a rotation policy, held 1.2 MB and had not
 * been written to in five weeks; `app.log`, the file without one, held 360 MB and was
 * still growing. The policy was attached to the log that does not grow.
 *
 * Size, not time, is the right trigger here. Swoole's log gets a line per server event,
 * so a daily window bounds it; the application log gets a line per anything, and a single
 * misbehaving diagnostic can write six thousand lines a second (which is what happened).
 * A daily window would have produced one 300 MB file instead of many small ones.
 *
 * Pruning is delegated rather than reimplemented: {@see SwooleLogRetention} is not
 * actually Swoole-specific — it takes a path and a window — and its safety guard only
 * deletes siblings carrying a 6-to-14 digit suffix, so anything an operator's own
 * logrotate left nearby survives. {@see SUFFIX_FORMAT} is chosen to land inside that
 * guard, which is why rotation here and retention there compose without either knowing
 * about the other.
 */
final readonly class AppLogRotation
{
    /** Default ceiling for one log file. Five of these is a bounded, greppable corpus. */
    public const int DEFAULT_MAX_BYTES = 33_554_432;

    /** Set the ceiling to this or lower to let the log grow without bound. */
    public const int DISABLED = 0;

    /**
     * 14 digits, so a rotated file matches the `^\d{6,14}$` sibling guard in
     * {@see SwooleLogRetention::prune()}. Changing this format silently orphans every
     * rotated file, because pruning would stop recognising them as ours.
     */
    public const string SUFFIX_FORMAT = 'YmdHis';

    public function __construct(
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
        private int $keepDays = 14,
    ) {}

    /**
     * Parse LOG_MAX_BYTES. Deliberately stricter than `is_numeric()` for the same reason
     * {@see SwooleLogRetention::daysFromEnv()} is: '32e6' would cast to 32 and turn a
     * generous ceiling into a rotation on every write.
     */
    public static function maxBytesFromEnv(mixed $value, int $default = self::DEFAULT_MAX_BYTES): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? $default : $parsed;
    }

    public function isEnabled(): bool
    {
        return $this->maxBytes > self::DISABLED;
    }

    public function shouldRotate(int $currentBytes): bool
    {
        return $this->isEnabled() && $currentBytes >= $this->maxBytes;
    }

    /**
     * Where a full log file moves to. Collisions are resolved rather than overwritten:
     * a burst can cross the ceiling twice inside one second, and losing the first slice
     * would delete exactly the lines that explain the burst.
     */
    public function rotatedPath(string $path, int $now, ?callable $exists = null): string
    {
        $exists ??= static fn (string $candidate): bool => file_exists($candidate);
        $base = $path . '.' . date(self::SUFFIX_FORMAT, $now);
        if (!$exists($base)) {
            return $base;
        }

        // Keep the digits-only suffix intact — a '-2' would fall outside the retention
        // guard and the file would never be pruned. Second-resolution collisions borrow
        // from the next second instead, which stays sortable and stays prunable.
        for ($offset = 1; $offset <= 60; ++$offset) {
            $candidate = $path . '.' . date(self::SUFFIX_FORMAT, $now + $offset);
            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        return $base;
    }

    /**
     * Move the current log aside and drop aged-out slices.
     *
     * Best-effort by construction: a logger that throws while logging turns a warning
     * into an outage. A failed rename simply means the file keeps growing until the next
     * attempt, which is strictly better than the alternative.
     *
     * @return string|null the path the log was moved to, or null when nothing rotated
     */
    public function rotate(string $path, ?int $now = null): ?string
    {
        if (!$this->isEnabled() || !is_file($path)) {
            return null;
        }

        $now ??= time();
        $target = $this->rotatedPath($path, $now);
        if (!@rename($path, $target)) {
            // Lost the race to another worker, which already moved it. Nothing to do:
            // the file is rotated either way, and that was the entire goal.
            return null;
        }

        (new SwooleLogRetention($path, $this->keepDays))->prune($now);

        return $target;
    }
}
