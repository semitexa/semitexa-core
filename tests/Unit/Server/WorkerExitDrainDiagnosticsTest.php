<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Core\Server\SwooleBootstrap;
use Swoole\Coroutine;

/**
 * The exit-drain breadcrumb, after semitexa/semitexa-core#94.
 *
 * A worker asked to exit clears its timers and cancels parked coroutines. The
 * counter only ever tallied SUCCESSFUL cancellations, so a worker held past
 * max_wait_time logged "cancelled N" and said nothing about the coroutine
 * actually keeping it hostage — which is the one fact needed to explain a
 * "worker exit timeout, forced termination", and the state the reported SIGSEGV
 * occurred in.
 *
 * A coroutine blocked inside a driver syscall cannot be interrupted, so refusal
 * is expected in production; what was missing was saying where it was parked.
 * These tests pin the describing half, because an unfired diagnostic proves
 * nothing about whether it works.
 */
final class WorkerExitDrainDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
    }

    private static function describe(int $cid): string
    {
        $m = new ReflectionMethod(SwooleBootstrap::class, 'describeCoroutine');
        $m->setAccessible(true);

        return (string) $m->invoke(null, $cid);
    }

    #[Test]
    public function it_names_where_a_parked_coroutine_is_waiting(): void
    {
        $description = null;

        Coroutine\run(static function () use (&$description): void {
            $gate = new Coroutine\Channel(1);
            $ready = new Coroutine\Channel(1);
            $parked = Coroutine::create(static function () use ($gate, $ready): void {
                // Signal readiness instead of sleeping a fixed interval: a timing
                // guess is flaky on a loaded runner, and the push happens
                // immediately before the park it announces.
                $ready->push(true);
                $gate->pop(2.0);
            });

            $ready->pop(2.0);
            $description = self::describe($parked);

            $gate->push(true);
        });

        self::assertIsString($description);
        self::assertStringNotContainsString('unavailable', $description, 'a live coroutine must be describable');
        self::assertStringNotContainsString('empty backtrace', $description);
        self::assertMatchesRegularExpression('/\.php:\d+/', $description, 'the trail carries file:line');
    }

    #[Test]
    public function a_dead_coroutine_degrades_to_a_placeholder_instead_of_throwing(): void
    {
        // Introspection must never be the reason a worker fails to exit, so the
        // describing path swallows its own failures.
        $description = null;

        Coroutine\run(static function () use (&$description): void {
            $description = self::describe(999999);
        });

        self::assertIsString($description);
        self::assertStringContainsString('unknown', $description);
    }

    #[Test]
    public function the_trail_is_bounded(): void
    {
        // Only the top frames go to the log: this runs during teardown, and an
        // unbounded trace per stuck coroutine would bury the very line someone
        // is grepping for.
        $description = null;

        Coroutine\run(static function () use (&$description): void {
            $gate = new Coroutine\Channel(1);
            $ready = new Coroutine\Channel(1);
            $deep = Coroutine::create(static function () use ($gate, $ready): void {
                $recurse = static function (int $n) use (&$recurse, $gate, $ready): void {
                    if ($n > 0) {
                        $recurse($n - 1);

                        return;
                    }
                    $ready->push(true);
                    $gate->pop(2.0);
                };
                $recurse(12);
            });

            $ready->pop(2.0);
            $description = self::describe($deep);
            $gate->push(true);
        });

        self::assertIsString($description);
        self::assertLessThanOrEqual(4, substr_count($description, '<-') + 1, 'at most four frames');
    }
}
