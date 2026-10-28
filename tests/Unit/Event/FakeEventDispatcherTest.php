<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\FakeEventDispatcher;

final class FakeEventDispatcherTest extends TestCase
{
    #[Test]
    public function it_records_what_was_dispatched_and_says_what_was_not(): void
    {
        $events = new FakeEventDispatcher();
        $events->dispatch(new FakeOrderPaid(42));
        $events->dispatch(new FakeOrderPaid(43));

        $events->assertDispatched(FakeOrderPaid::class);
        $events->assertDispatched(FakeOrderPaid::class, static fn (FakeOrderPaid $e): bool => $e->orderId === 43);
        $events->assertDispatchedTimes(FakeOrderPaid::class, 2);
        $events->assertNotDispatched(FakeOrderRefunded::class);
        self::assertCount(2, $events->dispatchedOf(FakeOrderPaid::class));

        foreach ([
            fn () => $events->assertDispatched(FakeOrderRefunded::class),
            fn () => $events->assertDispatched(FakeOrderPaid::class, static fn (FakeOrderPaid $e): bool => $e->orderId === 1),
            fn () => $events->assertDispatchedTimes(FakeOrderPaid::class, 1),
            fn () => $events->assertNotDispatched(FakeOrderPaid::class),
            fn () => $events->assertNothingDispatched(),
        ] as $i => $failing) {
            try {
                $failing();
                self::fail("assertion {$i} passed");
            } catch (\LogicException $e) {
                self::assertStringContainsString('FakeOrder', $e->getMessage());
            }
        }

        $events->reset();
        $events->assertNothingDispatched();
    }

    #[Test]
    public function create_builds_the_event_as_the_real_dispatcher_does(): void
    {
        $event = (new FakeEventDispatcher())->create(FakeOrderPaid::class, ['orderId' => 7]);

        self::assertInstanceOf(FakeOrderPaid::class, $event);
        self::assertSame(7, $event->orderId);
    }
}

final class FakeOrderPaid
{
    public function __construct(public int $orderId = 0) {}
}

final class FakeOrderRefunded
{
}
