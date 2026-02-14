<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

/**
 * Holds the Swoole Table used for session storage. Set from server.php before workers start.
 */
final class SwooleSessionTableHolder
{
    private static ?\Swoole\Table $table = null;

    public static function setTable(\Swoole\Table $table): void
    {
        self::$table = $table;
    }

    public static function getTable(): ?\Swoole\Table
    {
        return self::$table;
    }

    public static function hasTable(): bool
    {
        return self::$table !== null;
    }
}
