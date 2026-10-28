<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Environment;

/**
 * Where a server's rate-limit counters live, from RATE_LIMIT_STORE:
 *
 * - `auto` (default): Redis when REDIS_HOST is set, else a Swoole\Table that
 *   every worker of this server shares;
 * - `redis`, `table`: that store;
 * - `memory`: each worker counts alone, so N workers admit N times a limit.
 */
enum RateLimitStoreChoice: string
{
    case Auto = 'auto';
    case Redis = 'redis';
    case Table = 'table';
    case Memory = 'memory';

    public const TABLE_STATE_KEY = 'core.rate_limit_table';
    public const DEFAULT_TABLE_SIZE = 65536;

    public static function configured(): self
    {
        $value = strtolower(trim((string) Environment::getEnvValue('RATE_LIMIT_STORE', 'auto')));

        return self::tryFrom($value) ?? self::Auto;
    }

    public static function isValidSetting(string $value): bool
    {
        return self::tryFrom(strtolower(trim($value))) !== null;
    }

    public static function tableSize(): int
    {
        $value = trim((string) Environment::getEnvValue('RATE_LIMIT_TABLE_SIZE', ''));

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_TABLE_SIZE;
    }

    /** Whether a server started with this setting creates the shared table (as auto's fallback too). */
    public function wantsTable(): bool
    {
        return $this === self::Auto || $this === self::Table;
    }

    public function wantsRedis(): bool
    {
        return $this === self::Auto || $this === self::Redis;
    }
}
