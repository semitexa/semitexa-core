<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

use Semitexa\Core\Attribute\WorkerState;

/**
 * Holds the Swoole Table used for session storage. Set from server.php before workers start.
 */
final class SwooleSessionTableHolder
{
    #[WorkerState('The shared-memory session table created before workers start.')]
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
