<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\WorkerDrainSignal;

final class WorkerDrainSignalTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerDrainSignal::reset();
    }

    #[Test]
    public function a_fresh_worker_is_not_draining(): void
    {
        self::assertFalse(WorkerDrainSignal::isDraining());
    }

    #[Test]
    public function the_signal_is_sticky_once_raised(): void
    {
        // Stickiness is the whole design. WorkerExit re-fires for the entire drain window
        // and the scans that read this are thousands of uninterruptible reads long, so a
        // signal that could lapse between two of them would be no signal at all.
        WorkerDrainSignal::begin();

        self::assertTrue(WorkerDrainSignal::isDraining());
        self::assertTrue(WorkerDrainSignal::isDraining());

        WorkerDrainSignal::begin();

        self::assertTrue(WorkerDrainSignal::isDraining(), 'a repeated WorkerExit tick must not toggle it');
    }

    #[Test]
    public function reset_is_available_so_one_test_process_can_host_many_worker_lifetimes(): void
    {
        WorkerDrainSignal::begin();
        WorkerDrainSignal::reset();

        self::assertFalse(WorkerDrainSignal::isDraining());
    }
}
