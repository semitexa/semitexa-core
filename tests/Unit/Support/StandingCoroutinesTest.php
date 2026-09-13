<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\StandingCoroutines;

/**
 * Some framework coroutines are supposed to sit in read() for the life of the
 * worker: a pub/sub receiver, a lease heartbeat, a queue consumer. The
 * Observatory judges a long-lived coroutine by what is behind it — an sse
 * process means a session, an http process means a stuck request, nothing
 * means a leak — and standing work has nothing behind it, so it lands in the
 * leak bucket and is wrong there.
 *
 * This is how such a coroutine says what it is waiting for. It is a worker-wide
 * map keyed by coroutine id, not request state, so a plain static is the right
 * shape here rather than CoroutineLocal.
 */
final class StandingCoroutinesTest extends TestCase
{
    protected function setUp(): void
    {
        StandingCoroutines::reset();
    }

    protected function tearDown(): void
    {
        StandingCoroutines::reset();
    }

    #[Test]
    public function nothing_is_standing_until_something_says_so(): void
    {
        self::assertSame([], StandingCoroutines::all());
    }

    #[Test]
    public function a_declaration_carries_the_label_the_reason_and_when_it_parked(): void
    {
        StandingCoroutines::declareFor(7, 'live push receiver', 'subscribed to 3 channels');

        $all = StandingCoroutines::all();

        self::assertArrayHasKey(7, $all);
        self::assertSame('live push receiver', $all[7]['label']);
        self::assertSame('subscribed to 3 channels', $all[7]['reason']);
        self::assertIsFloat($all[7]['since']);
        self::assertGreaterThan(0.0, $all[7]['since']);
    }

    #[Test]
    public function declaring_again_from_the_same_coroutine_replaces_rather_than_duplicates(): void
    {
        StandingCoroutines::declareFor(7, 'live push receiver', 'subscribed to 3 channels');
        StandingCoroutines::declareFor(7, 'live push receiver', 'subscribed to 5 channels');

        $all = StandingCoroutines::all();

        self::assertCount(1, $all);
        self::assertSame('subscribed to 5 channels', $all[7]['reason'], 'the newest reason is the true one');
    }

    #[Test]
    public function a_coroutine_that_finished_is_forgotten(): void
    {
        StandingCoroutines::declareFor(7, 'live push receiver', 'subscribed');
        StandingCoroutines::forgetFor(7);

        self::assertSame([], StandingCoroutines::all());
    }

    /**
     * A coroutine can end without its deferred cleanup running — a cancelled
     * park is the case this runtime actually produces. The reader knows which
     * coroutines exist, so it says so, and the entry goes.
     */
    #[Test]
    public function declarations_for_coroutines_that_no_longer_exist_are_dropped(): void
    {
        StandingCoroutines::declareFor(7, 'receiver', 'subscribed');
        StandingCoroutines::declareFor(9, 'heartbeat', 'renewing a lease');

        $live = StandingCoroutines::all([9]);

        self::assertSame([9], array_keys($live));
        self::assertSame(
            [9],
            array_keys(StandingCoroutines::all()),
            'the dead one is gone for good, not filtered out of one answer',
        );
    }

    #[Test]
    public function an_empty_live_set_clears_everything(): void
    {
        StandingCoroutines::declareFor(7, 'receiver', 'subscribed');

        self::assertSame([], StandingCoroutines::all([]));
        self::assertSame([], StandingCoroutines::all());
    }

    /**
     * Declaring outside a coroutine has to be harmless: the CLI, the tests and
     * any non-Swoole host all run this code, and an observability aid that
     * throws there would be worse than the blindness it fixes.
     */
    #[Test]
    public function declaring_outside_a_coroutine_is_a_no_op(): void
    {
        StandingCoroutines::declare('receiver', 'subscribed');
        StandingCoroutines::forget();

        self::assertSame([], StandingCoroutines::all());
    }

    #[Test]
    public function a_label_and_reason_are_trimmed_and_bounded(): void
    {
        StandingCoroutines::declareFor(7, '  receiver  ', str_repeat('x', 500));

        $all = StandingCoroutines::all();

        self::assertSame('receiver', $all[7]['label']);
        self::assertLessThanOrEqual(200, strlen($all[7]['reason']), 'a reason is a sentence, not a payload');
    }
}
