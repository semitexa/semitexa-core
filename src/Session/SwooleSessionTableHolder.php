<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

use Semitexa\Core\Attribute\WorkerState;

/**
 * Holds the Swoole Table used for session storage, and the locks its writers
 * share. Set from server.php before workers start.
 */
final class SwooleSessionTableHolder
{
    /**
     * Locks per table. A session id always maps to the same one, so two ids
     * wait for each other only when they share a stripe.
     */
    public const LOCK_STRIPES = 64;

    #[WorkerState('The shared-memory session table created before workers start.')]
    private static ?\Swoole\Table $table = null;

    /** @var list<\Swoole\Lock> */
    #[WorkerState('The shared-memory locks of the session table, created with it before workers start.')]
    private static array $locks = [];

    /**
     * Must run before the server forks its workers: the table and the locks
     * are shared memory only for the processes forked after this call.
     */
    public static function setTable(\Swoole\Table $table): void
    {
        self::$table = $table;
        self::$locks = self::createLocks();
    }

    public static function getTable(): ?\Swoole\Table
    {
        return self::$table;
    }

    public static function hasTable(): bool
    {
        return self::$table !== null;
    }

    /**
     * The locks that go with {@see getTable()}; none while there is no table.
     *
     * @return list<\Swoole\Lock>
     */
    public static function getLocks(): array
    {
        return self::$locks;
    }

    /**
     * A set of mutexes for one session table. A process forked after this
     * call shares them; one that makes its own set shares nothing.
     *
     * @return list<\Swoole\Lock>
     */
    public static function createLocks(int $stripes = self::LOCK_STRIPES): array
    {
        $locks = [];
        for ($i = 0; $i < max(1, $stripes); $i++) {
            $locks[] = new \Swoole\Lock(SWOOLE_MUTEX);
        }

        return $locks;
    }
}
