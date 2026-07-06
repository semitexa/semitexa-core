<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

/**
 * Per-worker registry of background {@see \Swoole\Timer} ids, so they can be
 * cleared as one group when the worker stops ({@see ClearWorkerTimersListener}).
 *
 * Why this exists: a `Timer::tick` armed at worker start keeps firing during
 * worker teardown. A tick that then touches the database can park its coroutine
 * on a socket read that will never complete (the DB may already be going down
 * in the same restart), and once it is the last coroutine standing Swoole
 * aborts the worker with FATAL "all coroutines are asleep - deadlock!" —
 * observed live from the tasks tick during a container restart.
 *
 * Every listener that arms a recurring background timer registers the id here
 * (in addition to any static id it keeps for its own re-arm guard). Static
 * state is per worker process, which is exactly the scope a worker timer has.
 */
final class WorkerTimerRegistry
{
    /** @var list<int> */
    private static array $timerIds = [];

    public static function register(int $timerId): void
    {
        if ($timerId > 0) {
            self::$timerIds[] = $timerId;
        }
    }

    /** Clear every registered timer. Safe to call repeatedly / outside Swoole. */
    public static function clearAll(): void
    {
        if (class_exists(\Swoole\Timer::class, false)) {
            foreach (self::$timerIds as $id) {
                \Swoole\Timer::clear($id);
            }
        }
        self::$timerIds = [];
    }

    /** @return list<int> */
    public static function all(): array
    {
        return self::$timerIds;
    }
}
