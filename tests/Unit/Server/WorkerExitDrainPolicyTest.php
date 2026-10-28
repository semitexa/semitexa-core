<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\InFlightRequests;
use Semitexa\Core\Server\WorkerExitDrainPolicy;

/**
 * A worker that leaves gracefully lets the requests it is serving finish.
 *
 * MEASURED 2026-10-16 on the dev stack, SWOOLE_MAX_REQUEST=20 and four
 * concurrent clients: every recycle cancelled the coroutines of the requests
 * still running, so their Redis reads failed with "Operation canceled", the
 * session store was read as down (the visitor got a transient guest session
 * for that request) and the Redis breaker opened; 3 of 120 requests got no
 * answer at all. The drain cancelled everything on its first pass, which is
 * right for a parked SSE stream or a timer body and wrong for a request a
 * few milliseconds from its response.
 */
final class WorkerExitDrainPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        InFlightRequests::reset();
    }

    #[Test]
    public function a_request_in_flight_is_spared_within_the_grace_window(): void
    {
        $policy = new WorkerExitDrainPolicy(graceSeconds: 2.0);

        self::assertFalse($policy->shouldCancel(isServingRequest: true, secondsSinceDrainBegan: 0.0));
        self::assertFalse($policy->shouldCancel(isServingRequest: true, secondsSinceDrainBegan: 1.99));
    }

    /** A request still running at the end of the window is a stream, or stuck. */
    #[Test]
    public function a_request_still_running_after_the_window_is_cancelled(): void
    {
        $policy = new WorkerExitDrainPolicy(graceSeconds: 2.0);

        self::assertTrue($policy->shouldCancel(isServingRequest: true, secondsSinceDrainBegan: 2.0));
    }

    /** Timers' bodies, subscribers, warm-ups: what the drain was written for. */
    #[Test]
    public function anything_else_is_cancelled_at_once(): void
    {
        $policy = new WorkerExitDrainPolicy(graceSeconds: 2.0);

        self::assertTrue($policy->shouldCancel(isServingRequest: false, secondsSinceDrainBegan: 0.0));
    }

    /** The window must close before Swoole kills the worker at max_wait_time. */
    #[Test]
    public function the_window_ends_a_second_before_the_forced_kill(): void
    {
        self::assertSame(2.0, WorkerExitDrainPolicy::forMaxWaitTime(3)->graceSeconds);
        self::assertSame(0.0, WorkerExitDrainPolicy::forMaxWaitTime(1)->graceSeconds);
        self::assertSame(0.0, WorkerExitDrainPolicy::forMaxWaitTime(0)->graceSeconds);
    }

    #[Test]
    public function the_registry_knows_which_coroutines_serve_a_request(): void
    {
        InFlightRequests::enter(41);
        InFlightRequests::enter(42);
        InFlightRequests::leave(41);

        self::assertFalse(InFlightRequests::has(41));
        self::assertTrue(InFlightRequests::has(42));
        self::assertSame(1, InFlightRequests::count());
    }

    /**
     * Found in review: a request that waits on coroutines it spawned (the
     * webhook DNS check, deferred renders) was spared while its children were
     * cancelled, and it answered with what they never returned.
     */
    #[Test]
    public function a_coroutine_a_request_spawned_counts_as_the_request(): void
    {
        $seen = [];
        \Swoole\Coroutine\run(static function () use (&$seen): void {
            $request = \Semitexa\Core\Support\Row::asInt(\Swoole\Coroutine::getCid());
            InFlightRequests::enter($request);
            $child = 0;
            $grandchild = 0;
            // The children park on a channel only this coroutine closes, so
            // both are alive for every assertion below (a sleep raced them).
            $release = new \Swoole\Coroutine\Channel(1);
            $wg = new \Swoole\Coroutine\WaitGroup();
            $wg->add();
            \Swoole\Coroutine::create(static function () use (&$child, &$grandchild, $wg, $release): void {
                $child = \Semitexa\Core\Support\Row::asInt(\Swoole\Coroutine::getCid());
                $wg->add();
                \Swoole\Coroutine::create(static function () use (&$grandchild, $wg, $release): void {
                    $grandchild = \Semitexa\Core\Support\Row::asInt(\Swoole\Coroutine::getCid());
                    $release->pop();
                    $wg->done();
                });
                $release->pop();
                $wg->done();
            });
            $seen['alive'] = \Swoole\Coroutine::exists($child) && \Swoole\Coroutine::exists($grandchild);
            $seen['child'] = InFlightRequests::servesRequest($child);
            $seen['grandchild'] = InFlightRequests::servesRequest($grandchild);

            // Once the request turns into a stream it is the drain's to cancel,
            // and so is everything it spawned.
            InFlightRequests::streaming();
            $seen['streamItself'] = InFlightRequests::servesRequest($request);
            $seen['streamChild'] = InFlightRequests::servesRequest($child);
            $seen['stillAlive'] = \Swoole\Coroutine::exists($child);
            $release->close();
            $wg->wait();
        });

        self::assertSame(['alive' => true, 'child' => true, 'grandchild' => true, 'streamItself' => false, 'streamChild' => false, 'stillAlive' => true], $seen);
    }

    #[Test]
    public function a_coroutine_outside_any_request_does_not_serve_one(): void
    {
        $serves = null;
        \Swoole\Coroutine\run(static function () use (&$serves): void {
            $serves = InFlightRequests::servesRequest(\Semitexa\Core\Support\Row::asInt(\Swoole\Coroutine::getCid()));
        });

        self::assertFalse($serves);
    }

    /** 3 s cancelled every request longer than 2 s at each recycle: 13 HTTP 500s on one route in a 50 h soak. */
    #[Test]
    public function a_leaving_worker_waits_ten_seconds_unless_swoole_max_wait_time_says_otherwise(): void
    {
        $previous = getenv('SWOOLE_MAX_WAIT_TIME');
        // Unset in the process falls back to the project's env files, which may
        // set it; for the default they are held empty, then put back.
        $envFiles = new \ReflectionProperty(\Semitexa\Core\Support\EnvFileCache::class, 'values');
        $parsed = $envFiles->getValue();
        try {
            putenv('SWOOLE_MAX_WAIT_TIME');
            $envFiles->setValue(null, []);
            self::assertSame(10, \Semitexa\Core\Server\ServerConfigurator::maxWaitSeconds());
            self::assertSame(9.0, WorkerExitDrainPolicy::forMaxWaitTime(\Semitexa\Core\Server\ServerConfigurator::maxWaitSeconds())->graceSeconds);
            $envFiles->setValue(null, ['SWOOLE_MAX_WAIT_TIME' => '30']);
            self::assertSame(30, \Semitexa\Core\Server\ServerConfigurator::maxWaitSeconds(), 'unset in the process, the env files decide');
            putenv('SWOOLE_MAX_WAIT_TIME=30');
            self::assertSame(30, \Semitexa\Core\Server\ServerConfigurator::maxWaitSeconds());
            putenv('SWOOLE_MAX_WAIT_TIME=1');
            self::assertSame(2, \Semitexa\Core\Server\ServerConfigurator::maxWaitSeconds(), 'at least a second of grace');
            putenv('SWOOLE_MAX_WAIT_TIME=ten');
            self::assertSame(10, \Semitexa\Core\Server\ServerConfigurator::maxWaitSeconds());
        } finally {
            putenv($previous === false ? 'SWOOLE_MAX_WAIT_TIME' : 'SWOOLE_MAX_WAIT_TIME=' . $previous);
            $envFiles->setValue(null, $parsed);
        }
    }
}
