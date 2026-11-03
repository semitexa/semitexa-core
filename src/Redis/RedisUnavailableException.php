<?php

declare(strict_types=1);

namespace Semitexa\Core\Redis;

use Predis\Connection\ConnectionException;

/**
 * Raised instead of calling Redis while {@see RedisCircuitBreaker} is open.
 * Carries the failure that opened it, as message and as getPrevious().
 */
final class RedisUnavailableException extends \RuntimeException
{
    public static function from(ConnectionException $failure): self
    {
        return new self('Redis unavailable (failing fast): ' . $failure->getMessage(), 0, $failure);
    }

    /**
     * Every pooled client and every extra one are borrowed: Redis is not
     * answering them fast enough, and another connection would only add load.
     * Callers degrade on it as on an outage; it does not open the breaker.
     */
    public static function poolExhausted(int $size, int $extra, float $waitedSeconds): self
    {
        return new self(sprintf('Redis pool exhausted: %d pooled and %d extra clients borrowed, none returned within %.1f s', $size, $extra, $waitedSeconds));
    }
}
