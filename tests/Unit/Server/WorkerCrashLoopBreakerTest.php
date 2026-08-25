<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\WorkerCrashLoopBreaker;
use Swoole\Table;

/**
 * The tally that tells a repeat from a one-off.
 *
 * Swoole respawns every dead worker and cannot be told not to, so a worker that dies
 * on its own boot path is reborn into the same death indefinitely — at full CPU, and
 * looking like an ordinary one-off crash each time round. These tests pin the two
 * decisions that separate that loop from bad luck: crashes only accumulate while they
 * keep arriving inside the window, and the alarm repeats rather than firing once into
 * a log nobody was tailing.
 *
 * Time is passed in rather than read, so the window can be exercised without sleeping.
 */
final class WorkerCrashLoopBreakerTest extends TestCase
{
    private const NOW = 1_780_000_000;

    protected function setUp(): void
    {
        if (!class_exists(Table::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
    }

    #[Test]
    public function a_worker_that_has_never_crashed_counts_zero(): void
    {
        $breaker = new WorkerCrashLoopBreaker(4);

        self::assertSame(0, $breaker->crashCount(0, self::NOW));
        self::assertFalse($breaker->isTripped(0, self::NOW));
    }

    #[Test]
    public function consecutive_crashes_inside_the_window_accumulate(): void
    {
        $breaker = new WorkerCrashLoopBreaker(4);

        self::assertSame(1, $breaker->recordCrash(0, self::NOW));
        self::assertSame(2, $breaker->recordCrash(0, self::NOW + 60));
        self::assertSame(3, $breaker->recordCrash(0, self::NOW + 120));
        self::assertSame(3, $breaker->crashCount(0, self::NOW + 120));
    }

    #[Test]
    public function the_breaker_trips_at_the_threshold_and_not_before(): void
    {
        $breaker = new WorkerCrashLoopBreaker(4);
        $at = self::NOW;

        for ($i = 1; $i < WorkerCrashLoopBreaker::TRIP_THRESHOLD; $i++) {
            $breaker->recordCrash(0, $at);
            self::assertFalse($breaker->isTripped(0, $at), "tripped early at crash {$i}");
            $at += 10;
        }

        $breaker->recordCrash(0, $at);
        self::assertTrue($breaker->isTripped(0, $at));
    }

    #[Test]
    public function a_crash_beyond_the_window_starts_the_count_over(): void
    {
        $breaker = new WorkerCrashLoopBreaker(4);

        $breaker->recordCrash(0, self::NOW);
        $breaker->recordCrash(0, self::NOW + 10);

        $late = self::NOW + 10 + WorkerCrashLoopBreaker::WINDOW_SECONDS + 1;
        self::assertSame(1, $breaker->recordCrash(0, $late), 'a distant crash is not part of the same loop');
    }

    #[Test]
    public function a_worker_that_stops_crashing_ages_back_to_clean(): void
    {
        $breaker = new WorkerCrashLoopBreaker(4);
        for ($i = 0; $i < WorkerCrashLoopBreaker::TRIP_THRESHOLD; $i++) {
            $breaker->recordCrash(0, self::NOW + $i);
        }
        self::assertTrue($breaker->isTripped(0, self::NOW));

        $later = self::NOW + WorkerCrashLoopBreaker::WINDOW_SECONDS + 60;
        self::assertSame(0, $breaker->crashCount(0, $later));
        self::assertFalse($breaker->isTripped(0, $later), 'a healed worker must be allowed its optional boot work again');
    }

    #[Test]
    public function workers_are_counted_independently(): void
    {
        $breaker = new WorkerCrashLoopBreaker(4);
        for ($i = 0; $i < WorkerCrashLoopBreaker::TRIP_THRESHOLD; $i++) {
            $breaker->recordCrash(0, self::NOW + $i);
        }

        self::assertTrue($breaker->isTripped(0, self::NOW));
        self::assertSame(0, $breaker->crashCount(1, self::NOW), 'one bad worker must not indict the others');
        self::assertFalse($breaker->isTripped(1, self::NOW));
    }

    #[Test]
    public function the_alarm_stays_silent_below_the_threshold(): void
    {
        for ($crashes = 0; $crashes < WorkerCrashLoopBreaker::TRIP_THRESHOLD; $crashes++) {
            self::assertFalse(WorkerCrashLoopBreaker::shouldAlarm($crashes), "alarmed at {$crashes}");
        }
    }

    #[Test]
    public function the_alarm_fires_on_tripping_then_repeats_on_a_fixed_cadence(): void
    {
        $trip = WorkerCrashLoopBreaker::TRIP_THRESHOLD;
        $every = WorkerCrashLoopBreaker::REALARM_EVERY;

        self::assertTrue(WorkerCrashLoopBreaker::shouldAlarm($trip));
        self::assertFalse(WorkerCrashLoopBreaker::shouldAlarm($trip + 1));
        self::assertTrue(WorkerCrashLoopBreaker::shouldAlarm($trip + $every));
        self::assertTrue(WorkerCrashLoopBreaker::shouldAlarm($trip + (2 * $every)));
    }

    #[Test]
    public function a_two_day_loop_would_have_alarmed_repeatedly(): void
    {
        // The incident this class exists for: 1703 respawns of one worker, ~67s apart,
        // and a single per-crash log line that read like an ordinary crash every time.
        $breaker = new WorkerCrashLoopBreaker(4);
        $alarms = 0;
        for ($i = 0; $i < 1703; $i++) {
            if (WorkerCrashLoopBreaker::shouldAlarm($breaker->recordCrash(0, self::NOW + ($i * 67)))) {
                $alarms++;
            }
        }

        self::assertGreaterThan(50, $alarms, 'a loop this long must be impossible to miss');
    }
}
