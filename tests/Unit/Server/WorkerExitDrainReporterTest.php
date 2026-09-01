<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\WorkerExitDrainReporter;

/**
 * The emitting half of the exit-drain diagnostic.
 *
 * Its describing half is pinned by {@see WorkerExitDrainDiagnosticsTest}, which needs a
 * live coroutine and therefore skips itself wherever Swoole is absent. Volume is the half
 * that actually broke, and it is pure decision-making, so nothing here touches Swoole —
 * a regression this expensive must not be provable only on machines that happen to have
 * the extension loaded.
 *
 * The clock is a parameter for the same reason it is one on {@see WorkerCrashLoopBreaker}:
 * the measured incidents ran at six thousand drain passes per second and at thirteen, and
 * a test that cannot state that difference cannot pin the fix.
 */
final class WorkerExitDrainReporterTest extends TestCase
{
    private const PARKED = 'file_get_contents (ClassDiscovery.php:474) <- runInitialization';

    #[Test]
    public function it_names_a_stuck_coroutine_on_first_sighting(): void
    {
        $reporter = new WorkerExitDrainReporter();

        $due = $reporter->report([36 => self::PARKED], 1000.0);

        self::assertCount(1, $due);
        self::assertSame(36, $due[0]['cid']);
        self::assertSame(self::PARKED, $due[0]['where']);
        self::assertSame(0.0, $due[0]['stuck_for']);
        self::assertFalse($due[0]['repeat'], 'the first line is not a repeat');
    }

    #[Test]
    public function the_measured_burst_collapses_to_a_single_line(): void
    {
        // Replays the incident this class exists for: worker 0, cid 36, 18284 identical
        // lines between 03:41:13 and 03:41:16. Same coroutine, same three seconds.
        $reporter = new WorkerExitDrainReporter();
        $lines = 0;

        for ($tick = 0; $tick < 18284; ++$tick) {
            $now = 1000.0 + (3.0 * $tick / 18284);
            $lines += count($reporter->report([36 => self::PARKED], $now));
        }

        self::assertSame(1, $lines, '18284 drain passes over one unchanging fact is one line');

        $summary = $reporter->summary(1003.0);
        self::assertNotNull($summary);
        self::assertSame(18284, $summary['drain_ticks']);
        self::assertSame(1, $summary['coroutines']);
        self::assertSame(18283, $summary['suppressed_lines'], 'suppression is stated, not hidden');
    }

    #[Test]
    public function a_worker_held_past_the_window_says_so_again_with_the_duration(): void
    {
        // The other measured incident: cid 51 stuck for 22 minutes. Silence would be as
        // wrong as 17564 lines — the duration is the fact that explains a hung restart.
        $reporter = new WorkerExitDrainReporter();
        $lines = [];

        for ($second = 0; $second <= 1335; ++$second) {
            foreach ($reporter->report([51 => self::PARKED], 1000.0 + $second) as $sighting) {
                $lines[] = $sighting;
            }
        }

        $expected = 1 + (int) floor(1335 / WorkerExitDrainReporter::REALARM_AFTER_SECONDS);
        self::assertCount($expected, $lines);
        self::assertFalse($lines[0]['repeat']);
        self::assertTrue($lines[1]['repeat'], 'later lines are marked as re-alarms');
        self::assertEqualsWithDelta(1320.0, end($lines)['stuck_for'], 30.0, 'the last line carries the real age');
    }

    #[Test]
    public function each_coroutine_is_throttled_on_its_own(): void
    {
        // One noisy coroutine must not silence a second one that appears mid-drain.
        $reporter = new WorkerExitDrainReporter();

        $reporter->report([36 => self::PARKED], 1000.0);
        $due = $reporter->report([36 => self::PARKED, 51 => self::PARKED], 1000.5);

        self::assertCount(1, $due);
        self::assertSame(51, $due[0]['cid']);
    }

    #[Test]
    public function a_clean_drain_closes_without_a_summary_line(): void
    {
        $reporter = new WorkerExitDrainReporter();

        $reporter->report([], 1000.0);

        self::assertNull($reporter->summary(1001.0), 'a worker that exited cleanly says nothing');
    }

    #[Test]
    public function the_summary_names_the_coroutine_that_held_the_worker_longest(): void
    {
        $reporter = new WorkerExitDrainReporter();

        $reporter->report([36 => self::PARKED], 1000.0);
        $reporter->report([36 => self::PARKED, 51 => self::PARKED], 1200.0);

        $summary = $reporter->summary(1300.0);

        self::assertNotNull($summary);
        self::assertSame(36, $summary['longest_cid']);
        self::assertSame(300.0, $summary['held_for_seconds']);
        self::assertSame(2, $summary['coroutines']);
    }
}
