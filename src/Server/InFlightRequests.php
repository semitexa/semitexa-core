<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * The coroutines of this worker that are serving an HTTP request right now.
 *
 * Read by the exit drain, which must not cancel a request a few milliseconds
 * from its response (see {@see WorkerExitDrainPolicy}). Keyed by coroutine id
 * and written only by the request's own coroutine, on entry and in a finally,
 * so concurrent requests never touch each other's entry. Per worker process:
 * a fork starts with what its parent had, which is nothing — the server
 * forks before any request.
 */
final class InFlightRequests
{
    /** Deeper than any spawn chain a request builds; bounds the walk. */
    private const MAX_ANCESTRY = 16;

    /** @var array<int, true> */
    private static array $cids = [];

    public static function enter(int $cid): void
    {
        self::$cids[$cid] = true;
    }

    public static function leave(int $cid): void
    {
        unset(self::$cids[$cid]);
    }

    public static function has(int $cid): bool
    {
        return isset(self::$cids[$cid]);
    }

    /**
     * Does this coroutine serve a request in flight — itself, or through any
     * coroutine that spawned it? A request that waits on children (a DNS
     * check over a WaitGroup, deferred renders) is only spared if they are:
     * otherwise it answers with what they never returned.
     */
    public static function servesRequest(int $cid): bool
    {
        for ($hop = 0; $hop < self::MAX_ANCESTRY && $cid > 0; $hop++) {
            if (isset(self::$cids[$cid])) {
                return true;
            }
            $parent = \Swoole\Coroutine::getPcid($cid);
            if (!is_int($parent) || $parent === $cid) {
                return false;
            }
            $cid = $parent;
        }

        return false;
    }

    /**
     * The current request has become a long-lived stream (SSE): a leaving
     * worker cancels it at once, as before, rather than holding the drain open
     * for the grace window on a stream that will never end by itself.
     */
    public static function streaming(): void
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid > 0) {
            self::leave($cid);
        }
    }

    public static function count(): int
    {
        return count(self::$cids);
    }

    /** @internal Test seam. */
    public static function reset(): void
    {
        self::$cids = [];
    }
}
