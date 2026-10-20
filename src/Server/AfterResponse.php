<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Support\Row;

/**
 * Work that runs once the response is out: async event listeners, and async
 * payload handlers run in process without async events. The one place both
 * decide when that is.
 *
 * - In a request coroutine it runs as the coroutine exits, after the response
 *   is emitted, in the order scheduled (Coroutine::defer alone is LIFO). The
 *   request has left {@see InFlightRequests} by then, and a leaving worker
 *   cancels anything not in flight at once: the work enters it again for its
 *   run, so a recycle gives it the request's grace window instead of cutting it.
 * - In a re-run tick of a held-open connection ({@see tick()}) the coroutine
 *   exits only when the connection closes, hours later maybe: work deferred to
 *   then piled up one closure a tick and ran all at once, stale. It runs after
 *   the tick instead, once the connection's coroutine has moved on (its frame
 *   is written first), in one coroutine per connection that takes the work of
 *   every tick in order, in flight while it runs.
 * - Once a held-open connection has written its first frame ({@see holdOpen()})
 *   the response is out though the coroutine stays: what the request had
 *   scheduled, and what it schedules from then on outside a tick (a heartbeat,
 *   an auth refresh), goes to the same per-connection coroutine.
 * - Anywhere else (console, a queue worker, a consumer loop that never exits,
 *   PHPUnit) it runs inline.
 *
 * The work must not throw: nothing above a deferred callback can catch, and an
 * escaping throwable is fatal to the worker. Callers catch and log their own.
 */
final class AfterResponse
{
    private const QUEUE_KEY = 'semitexa.afterResponse.queue';
    private const TICK_KEY = 'semitexa.afterResponse.tick';
    /** The connection's work of every tick so far, and whether its drain runs. */
    private const CONNECTION_KEY = 'semitexa.afterResponse.connection';
    /** Set once a held-open connection's response is out: see holdOpen(). */
    private const HELD_OPEN_KEY = 'semitexa.afterResponse.heldOpen';

    /** @param \Closure(): void $work */
    public static function schedule(\Closure $work): void
    {
        if (!SwooleBootstrap::isRequestCoroutine()) {
            $work();
            return;
        }
        $context = \Swoole\Coroutine::getContext();
        if (!$context instanceof \ArrayObject) {
            $work();
            return;
        }

        $tick = $context[self::TICK_KEY] ?? null;
        if ($tick instanceof \ArrayObject) {
            $tick[] = $work;
            return;
        }

        if (($context[self::HELD_OPEN_KEY] ?? false) === true) {
            self::handToConnection($context, new \ArrayObject([$work]));
            return;
        }

        $queue = $context[self::QUEUE_KEY] ?? null;
        if ($queue instanceof \ArrayObject) {
            $queue[] = $work;
            return;
        }
        $queue = new \ArrayObject([$work]);
        $context[self::QUEUE_KEY] = $queue;
        $cid = Row::asInt(\Swoole\Coroutine::getCid());
        \Swoole\Coroutine::defer(static function () use ($queue, $cid): void {
            self::drain($queue, $cid);
        });
    }

    /**
     * The response of this request coroutine is out, though the coroutine
     * stays: a held-open connection (KISS, a resource stream) after its first
     * frame. Its coroutine exits only when the connection closes, and what
     * the request scheduled until now (an async handler declared before the
     * stream's, a listener of an event the first frame dispatched) waited for
     * that, hours maybe. It runs now, after the connection's coroutine yields,
     * and so does what is scheduled outside a tick from here on.
     */
    public static function holdOpen(): void
    {
        if (!SwooleBootstrap::isRequestCoroutine()) {
            return;
        }
        $context = \Swoole\Coroutine::getContext();
        if (!$context instanceof \ArrayObject) {
            return;
        }
        $context[self::HELD_OPEN_KEY] = true;

        $queue = $context[self::QUEUE_KEY] ?? null;
        if (!$queue instanceof \ArrayObject || count($queue) === 0) {
            return;
        }
        // Taken out, so the deferred drain finds nothing when the coroutine exits.
        /** @var \ArrayObject<int, \Closure(): void> $pending */
        $pending = new \ArrayObject();
        foreach ($queue as $work) {
            if ($work instanceof \Closure) {
                /** @var \Closure(): void $work only schedule() writes the queue */
                $pending[] = $work;
            }
        }
        $queue->exchangeArray([]);
        self::handToConnection($context, $pending);
    }

    /**
     * One re-run tick of a held-open connection: what $run schedules runs when
     * it returns (or throws), not when the connection closes.
     *
     * @template T
     * @param \Closure(): T $run
     * @return T
     */
    public static function tick(\Closure $run): mixed
    {
        $context = \Swoole\Coroutine::getCid() > 0 ? \Swoole\Coroutine::getContext() : null;
        if (!$context instanceof \ArrayObject) {
            return $run();
        }

        $outer = $context[self::TICK_KEY] ?? null;
        $tick = new \ArrayObject();
        $context[self::TICK_KEY] = $tick;
        try {
            return $run();
        } finally {
            self::endTick($context, $outer, $tick);
        }
    }

    /**
     * @param \ArrayObject<array-key, mixed>      $context
     * @param \ArrayObject<int, \Closure(): void> $tick
     */
    private static function endTick(\ArrayObject $context, mixed $outer, \ArrayObject $tick): void
    {
        if ($outer instanceof \ArrayObject) {
            // A tick inside a tick: its work goes after what the outer one scheduled first.
            $context[self::TICK_KEY] = $outer;
            foreach ($tick as $work) {
                $outer[] = $work;
            }

            return;
        }
        unset($context[self::TICK_KEY]);
        if (count($tick) > 0) {
            self::handToConnection($context, $tick);
        }
    }

    /**
     * One drain per connection, so the work of tick N+1 never runs beside or
     * before tick N's. It waits for the connection's coroutine to yield (write
     * its frame) before it runs anything.
     *
     * @param \ArrayObject<array-key, mixed>      $context the connection coroutine's
     * @param \ArrayObject<int, \Closure(): void> $tick
     */
    private static function handToConnection(\ArrayObject $context, \ArrayObject $tick): void
    {
        $connection = $context[self::CONNECTION_KEY] ?? null;
        if (!$connection instanceof AfterResponseConnection) {
            $connection = new AfterResponseConnection();
            $context[self::CONNECTION_KEY] = $connection;
        }
        foreach ($tick as $work) {
            $connection->pending->enqueue($work);
        }
        if ($connection->draining) {
            return;
        }
        $connection->draining = true;
        // Created here, so a failure is seen here: a drain that never started
        // and a flag left up would queue every later tick's work for good.
        $started = \Swoole\Coroutine::create(static function () use ($connection): void {
            $cid = Row::asInt(\Swoole\Coroutine::getCid());
            InFlightRequests::enter($cid);
            try {
                // A timer, so the connection's coroutine runs on until it yields.
                \Swoole\Coroutine::sleep(0.001);
                while (!$connection->pending->isEmpty()) {
                    self::runOne($connection->pending->dequeue());
                }
            } finally {
                $connection->draining = false;
                InFlightRequests::leave($cid);
            }
        });
        if ($started === false) {
            $dropped = count($connection->pending);
            $connection->pending = new \SplQueue();
            $connection->draining = false;
            FallbackErrorLogger::log('Work deferred by a re-run tick was dropped: no coroutine could be started', ['dropped' => $dropped]);
        }
    }

    /**
     * Callers catch their own, but a throwable that escapes here is fatal to
     * the worker, and with one worker to the site: caught once more, and said.
     *
     * @param \Closure(): void $work
     */
    private static function runOne(\Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            FallbackErrorLogger::log('Work deferred past the response failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * FIFO, in flight meanwhile. (Work it schedules runs inline: the request
     * coroutine has lost its request mark by the time this runs.)
     *
     * @param \ArrayObject<int, \Closure(): void> $queue
     */
    private static function drain(\ArrayObject $queue, int $cid): void
    {
        $wasInFlight = InFlightRequests::has($cid);
        InFlightRequests::enter($cid);
        try {
            for ($i = 0; $i < count($queue); $i++) {
                self::runOne($queue[$i]);
            }
        } finally {
            if (!$wasInFlight) {
                InFlightRequests::leave($cid);
            }
        }
    }
}
