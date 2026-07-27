<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * How often Swoole should start a new log file.
 *
 * Swoole's own setting is a bare integer constant, which makes `.env` unreadable
 * and a typo silent — `SWOOLE_LOG_ROTATION=5` would be accepted and mean nothing.
 * Naming the values keeps the env file self-documenting and lets an unknown value
 * fail loudly at boot instead of quietly disabling rotation.
 *
 * Note what Swoole does once rotation is on: it writes to `<log_file>.<date>` and
 * leaves the configured `log_file` itself as an empty placeholder. Anything that
 * reads the log by its configured name has to resolve the dated file — see
 * {@see SwooleLogRotation::resolveActiveFile()}.
 */
enum SwooleLogRotation: string
{
    case Single      = 'single';
    case Monthly     = 'monthly';
    case Daily       = 'daily';
    case Hourly      = 'hourly';
    case EveryMinute = 'every-minute';

    public static function fromEnv(string $value): self
    {
        $normalized = strtolower(trim($value));
        // '0'/'1' are what an operator copying Swoole's own docs would write.
        $normalized = match ($normalized) {
            '', '0', 'off', 'none', 'disabled' => 'single',
            '1'                                => 'monthly',
            '2'                                => 'daily',
            '3'                                => 'hourly',
            '4', 'minute'                      => 'every-minute',
            default                            => $normalized,
        };

        return self::tryFrom($normalized)
            ?? throw new \InvalidArgumentException(sprintf(
                'Unknown SWOOLE_LOG_ROTATION "%s". Expected one of: %s.',
                $value,
                implode(', ', array_column(self::cases(), 'value')),
            ));
    }

    /** The integer Swoole's `log_rotation` server option expects. */
    public function swooleValue(): int
    {
        return match ($this) {
            self::Single      => 0,
            self::Monthly     => 1,
            self::Daily       => 2,
            self::Hourly      => 3,
            self::EveryMinute => 4,
        };
    }

    public function isEnabled(): bool
    {
        return $this !== self::Single;
    }

    /**
     * The file Swoole is actually writing to right now.
     *
     * With rotation off this is the configured path. With rotation on the configured
     * path is an empty placeholder and the real content lives in the newest
     * `<log_file>.<date>` sibling, so readers must not assume the configured name.
     */
    public static function resolveActiveFile(string $configuredPath): string
    {
        // Restrict to Swoole's own numeric date suffix. A bare '.*' also matches
        // whatever an operator's logrotate left behind, and since the newest name
        // wins a lexical sort, a sibling like 'swoole.log.zip' would be served as
        // the live log.
        $rotated = array_values(array_filter(
            glob($configuredPath . '.*') ?: [],
            static fn(string $path) => preg_match('/\.\d{6,14}$/', $path) === 1,
        ));
        if ($rotated === []) {
            return $configuredPath;
        }

        // Swoole's suffix is a zero-padded date, so a plain sort is chronological.
        sort($rotated);
        $newest = (string) end($rotated);

        // A non-empty configured file means rotation was switched off after some
        // rotated files were already written; prefer whichever was touched last.
        if (is_file($configuredPath) && filesize($configuredPath) > 0) {
            return filemtime($configuredPath) >= filemtime($newest) ? $configuredPath : $newest;
        }

        return $newest;
    }
}
