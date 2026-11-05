<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\ServerConfigurator;
use Semitexa\Core\Server\WorkerRecycle;

/**
 * When a worker tells a client to go, and when it goes itself. That no request is
 * lost needs a real server: {@see \Semitexa\Core\Tests\Integration\Server\WorkerRecycleKeepsRequestsTest}.
 */
final class WorkerRecycleTest extends TestCase
{
    /** @return iterable<string, array{int, int, bool}> */
    public static function settings(): iterable
    {
        yield 'several workers' => [10000, 4, true];
        yield 'one worker: stop() would end the server' => [10000, 1, false];
        yield 'recycling switched off' => [0, 4, false];
    }

    #[Test]
    #[DataProvider('settings')]
    public function it_recycles_only_where_a_replacement_exists(int $maxRequests, int $workers, bool $expected): void
    {
        self::assertSame($expected, WorkerRecycle::manages($maxRequests, $workers));
        self::assertSame($expected, (new WorkerRecycle($maxRequests, $workers))->isActive());
    }

    #[Test]
    public function left_to_swoole_it_touches_nothing(): void
    {
        $recycle = new WorkerRecycle(1, 1);

        self::assertFalse($recycle->arrived(1));
        self::assertFalse($recycle->arrived(1));
        self::assertFalse($recycle->dueToLeave());
    }

    #[Test]
    public function a_connection_ends_after_a_tenth_of_the_limit(): void
    {
        $recycle = new WorkerRecycle(1000, 4);

        for ($i = 1; $i < 100; $i++) {
            self::assertFalse($recycle->arrived(7), "request $i");
        }
        self::assertTrue($recycle->arrived(7), 'the hundredth');
        self::assertFalse($recycle->arrived(8), 'another connection has its own count');
    }

    #[Test]
    public function a_closed_session_starts_again(): void
    {
        // Forgotten on close, or the map would grow with every client a worker meets.
        $recycle = new WorkerRecycle(20, 4);
        $recycle->arrived(7);

        $recycle->closed(7);

        self::assertFalse($recycle->arrived(7));
    }

    #[Test]
    public function the_worker_leaves_once_within_half_as_much_again(): void
    {
        $recycle = new WorkerRecycle(100, 4);

        $served = 0;
        while (!$recycle->dueToLeave()) {
            // A new connection each time: only the worker's own count is at work.
            $recycle->arrived(++$served);
            self::assertLessThanOrEqual(150, $served, 'it should have left by now');
        }

        self::assertGreaterThanOrEqual(100, $served);
        self::assertTrue($recycle->arrived(++$served), 'past its limit every response ends its connection');
        self::assertFalse($recycle->dueToLeave(), 'said once');
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function swooleLimits(): iterable
    {
        yield 'several workers: Swoole must not recycle as well' => [10000, 4, 0];
        yield 'one worker stays with Swoole' => [10000, 1, 10000];
        yield 'switched off' => [0, 4, 0];
    }

    #[Test]
    #[DataProvider('swooleLimits')]
    public function swoole_recycles_only_what_this_does_not(int $maxRequests, int $workers, int $expected): void
    {
        $env = new Environment(
            appEnv: 'test',
            appDebug: false,
            appName: 'Semitexa',
            appHost: 'localhost',
            appPort: 9502,
            swoolePort: 9502,
            swooleSsePort: 9503,
            swooleHost: '0.0.0.0',
            swooleWorkerNum: $workers,
            swooleMaxRequest: $maxRequests,
            swooleMaxCoroutine: 1,
            swooleLogFile: 'var/log/swoole.log',
            swooleLogLevel: 1,
            swooleSessionTableSize: 1,
            swooleSessionMaxBytes: 1,
            swooleSseWorkerTableSize: 1,
            swooleSseDeliverTableSize: 1,
            swooleSsePayloadMaxBytes: 1,
            corsAllowOrigin: '*',
            corsAllowMethods: 'GET',
            corsAllowHeaders: 'Content-Type',
            corsAllowCredentials: false,
        );

        self::assertSame($expected, (new ServerConfigurator($env))->getServerOptions()['max_request']);
    }

    #[Test]
    public function a_tiny_limit_still_counts(): void
    {
        // Storm runs set SWOOLE_MAX_REQUEST=5: a tenth of it must not become zero.
        self::assertTrue((new WorkerRecycle(5, 2))->arrived(1));
    }
}
