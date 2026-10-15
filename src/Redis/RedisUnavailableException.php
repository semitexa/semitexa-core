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
}
