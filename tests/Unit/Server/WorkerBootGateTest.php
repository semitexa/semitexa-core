<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\WorkerBootGate;
use Swoole\Coroutine;

/**
 * Swoole dispatches requests while onWorkerStart is parked on I/O. Before the
 * gate, the first request after every start met half-wired registries and
 * answered 500.
 */
final class WorkerBootGateTest extends TestCase
{
    #[Test]
    public function a_gate_that_was_never_closed_lets_requests_through(): void
    {
        self::assertTrue((new WorkerBootGate())->wait(0.01));
    }

    #[Test]
    public function a_request_arriving_mid_boot_waits_until_boot_finishes(): void
    {
        $order = [];

        Coroutine\run(static function () use (&$order): void {
            $gate = new WorkerBootGate();
            $gate->close();

            Coroutine::create(static function () use ($gate, &$order): void {
                $order[] = 'request arrives';
                $order[] = $gate->wait(5.0) ? 'request served' : 'request timed out';
            });

            Coroutine::sleep(0.05);
            $order[] = 'boot finished';
            $gate->open();
        });

        self::assertSame(['request arrives', 'boot finished', 'request served'], $order);
    }

    #[Test]
    public function a_boot_that_never_finishes_times_the_request_out(): void
    {
        $served = null;

        Coroutine\run(static function () use (&$served): void {
            $gate = new WorkerBootGate();
            $gate->close();
            $served = $gate->wait(0.05);
            $gate->open();
        });

        self::assertFalse($served);
    }
}
