<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\AfterResponse;
use Semitexa\Core\Server\InFlightRequests;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\Support\Row;

/**
 * When work deferred past the response runs (tk-deferred-work-on-held-open-connections):
 * async listeners, and async handlers without async events.
 */
final class AfterResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        InFlightRequests::reset();
    }

    #[Test]
    public function in_a_request_it_runs_after_the_request_in_the_order_scheduled(): void
    {
        $log = [];
        \Swoole\Coroutine\run(function () use (&$log): void {
            self::markAsRequestCoroutine();
            AfterResponse::schedule(static function () use (&$log): void {
                $log[] = 'first';
            });
            AfterResponse::schedule(static function () use (&$log): void {
                $log[] = 'second';
            });
            $log[] = 'response';
        });

        self::assertSame(['response', 'first', 'second'], $log);
    }

    /**
     * The request leaves InFlightRequests before its coroutine exits, and a
     * leaving worker cancels what is not in flight at once: a recycle cut the
     * work mid-way, with no retry. It is in flight while it runs, so the drain
     * gives it the request's grace window.
     */
    #[Test]
    public function the_work_is_in_flight_while_it_runs_and_not_after(): void
    {
        $seen = null;
        $cid = 0;
        \Swoole\Coroutine\run(function () use (&$seen, &$cid): void {
            self::markAsRequestCoroutine();
            $cid = Row::asInt(\Swoole\Coroutine::getCid());
            AfterResponse::schedule(static function () use (&$seen, $cid): void {
                $seen = InFlightRequests::servesRequest($cid);
            });
        });

        self::assertTrue($seen);
        self::assertFalse(InFlightRequests::has($cid));
    }

    /**
     * A held-open connection's coroutine exits when the connection closes.
     * Work deferred to then piled up one closure a re-run tick and ran all at
     * once, stale. It runs after the tick, once the connection's coroutine has
     * moved on (written its frame), in order across ticks, in flight meanwhile.
     */
    #[Test]
    public function in_a_tick_it_runs_after_the_tick_in_order_not_when_the_connection_closes(): void
    {
        $log = [];
        $inFlight = [];
        $drainCid = 0;
        \Swoole\Coroutine\run(function () use (&$log, &$inFlight, &$drainCid): void {
            self::markAsRequestCoroutine();
            $connection = Row::asInt(\Swoole\Coroutine::getCid());
            $work = static function (string $name) use (&$log, &$inFlight, &$drainCid, $connection): \Closure {
                return static function () use ($name, &$log, &$inFlight, &$drainCid, $connection): void {
                    $log[] = "start {$name}";
                    $drainCid = Row::asInt(\Swoole\Coroutine::getCid());
                    $inFlight[] = $drainCid !== $connection && InFlightRequests::has($drainCid);
                    // Yields: tick 2's work must still wait for it.
                    \Swoole\Coroutine::sleep(0.005);
                    $log[] = $name;
                };
            };
            foreach ([1, 2] as $n) {
                $log[] = AfterResponse::tick(static function () use ($work, $n): string {
                    AfterResponse::schedule($work("tick {$n} work a"));
                    AfterResponse::schedule($work("tick {$n} work b"));

                    return "frame {$n}";
                });
            }
            $log[] = 'frames written';
            \Swoole\Coroutine::sleep(0.1);
            $log[] = 'connection closes';
        });

        self::assertSame([
            'frame 1', 'frame 2', 'frames written',
            'start tick 1 work a', 'tick 1 work a', 'start tick 1 work b', 'tick 1 work b',
            'start tick 2 work a', 'tick 2 work a', 'start tick 2 work b', 'tick 2 work b',
            'connection closes',
        ], $log);
        self::assertSame([true, true, true, true], $inFlight, 'in a coroutine of its own, in flight');
        self::assertFalse(InFlightRequests::has($drainCid), 'and out of flight once done');
    }

    /**
     * A tick that comes while the drain is busy joins its queue; one that comes
     * after the drain has finished starts another. Work that throws, which no
     * caller should let happen, is caught and said: escaping, it ended the worker.
     */
    #[Test]
    public function later_ticks_join_a_busy_drain_or_start_a_new_one_and_a_throw_does_not_stop_it(): void
    {
        $log = [];
        \Swoole\Coroutine\run(function () use (&$log): void {
            self::markAsRequestCoroutine();
            $slow = static function () use (&$log): void {
                \Swoole\Coroutine::sleep(0.02);
                $log[] = 'slow';
            };
            AfterResponse::tick(static fn () => AfterResponse::schedule($slow));
            \Swoole\Coroutine::sleep(0.005);
            AfterResponse::tick(static function () use (&$log): void {
                AfterResponse::schedule(static function (): void {
                    throw new \RuntimeException('should have been caught');
                });
                AfterResponse::schedule(static function () use (&$log): void {
                    $log[] = 'joined';
                });
            });
            \Swoole\Coroutine::sleep(0.05);
            AfterResponse::tick(static function () use (&$log): void {
                AfterResponse::schedule(static function () use (&$log): void {
                    $log[] = 'fresh drain';
                });
            });
            \Swoole\Coroutine::sleep(0.02);
        });

        self::assertSame(['slow', 'joined', 'fresh drain'], $log);
    }

    #[Test]
    public function a_tick_inside_a_tick_runs_its_work_after_what_the_outer_one_scheduled(): void
    {
        $log = [];
        \Swoole\Coroutine\run(function () use (&$log): void {
            self::markAsRequestCoroutine();
            AfterResponse::tick(static function () use (&$log): void {
                AfterResponse::schedule(static function () use (&$log): void {
                    $log[] = 'outer';
                });
                AfterResponse::tick(static function () use (&$log): void {
                    AfterResponse::schedule(static function () use (&$log): void {
                        $log[] = 'inner';
                    });
                });
            });
            \Swoole\Coroutine::sleep(0.01);
        });

        self::assertSame(['outer', 'inner'], $log);
    }

    #[Test]
    public function a_tick_that_throws_still_runs_what_it_scheduled_and_ends_its_scope(): void
    {
        $log = [];
        \Swoole\Coroutine\run(function () use (&$log): void {
            self::markAsRequestCoroutine();
            try {
                AfterResponse::tick(static function () use (&$log): never {
                    AfterResponse::schedule(static function () use (&$log): void {
                        $log[] = 'tick work';
                    });
                    throw new \RuntimeException('tick failed');
                });
            } catch (\RuntimeException) {
                $log[] = 'caught';
            }
            AfterResponse::schedule(static function () use (&$log): void {
                $log[] = 'request work';
            });
            $log[] = 'response';
        });

        // No yield here: the coroutine exits first, so its own deferred work comes first.
        self::assertSame(['caught', 'response', 'request work', 'tick work'], $log);
    }

    /**
     * tk-held-open-first-run-and-listener-context. A held-open connection's
     * coroutine exits only when it closes: what the request scheduled before
     * its first frame (an async handler declared before the stream's) waited
     * for that, and so did a heartbeat's or an auth refresh's work outside a
     * tick. Once the response is out it runs after the connection yields,
     * in order, in flight meanwhile.
     */
    #[Test]
    public function once_held_open_what_was_scheduled_and_what_comes_outside_a_tick_runs_before_the_connection_closes(): void
    {
        $log = [];
        $inFlight = [];
        \Swoole\Coroutine\run(function () use (&$log, &$inFlight): void {
            self::markAsRequestCoroutine();
            $connection = Row::asInt(\Swoole\Coroutine::getCid());
            $work = static function (string $name) use (&$log, &$inFlight, $connection): \Closure {
                return static function () use ($name, &$log, &$inFlight, $connection): void {
                    $cid = Row::asInt(\Swoole\Coroutine::getCid());
                    $inFlight[] = $cid !== $connection && InFlightRequests::has($cid);
                    $log[] = $name;
                };
            };
            AfterResponse::schedule($work('before the first frame'));
            $log[] = 'first frame';
            AfterResponse::holdOpen();
            AfterResponse::schedule($work('heartbeat'));
            $log[] = 'loop waits';
            \Swoole\Coroutine::sleep(0.02);
            $log[] = 'connection closes';
        });

        self::assertSame(['first frame', 'loop waits', 'before the first frame', 'heartbeat', 'connection closes'], $log);
        self::assertSame([true, true], $inFlight);
    }

    #[Test]
    public function held_open_outside_a_request_does_nothing(): void
    {
        $log = [];
        AfterResponse::holdOpen();
        AfterResponse::schedule(static function () use (&$log): void {
            $log[] = 'work';
        });
        $log[] = 'after';

        self::assertSame(['work', 'after'], $log);
    }

    #[Test]
    public function outside_a_request_it_runs_inline(): void
    {
        $log = [];
        AfterResponse::schedule(static function () use (&$log): void {
            $log[] = 'work';
        });
        $log[] = 'after';

        self::assertSame(['work', 'after'], $log);
    }

    /** What SwooleBootstrap's onRequest sets on the coroutine a request runs in. */
    private static function markAsRequestCoroutine(): void
    {
        $key = (new \ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
        \Swoole\Coroutine::getContext()[$key] = true;
    }
}
