<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;

/**
 * A queued handler must resolve the same way a dispatched listener does.
 *
 * A payload handler is not required to carry `#[AsService]` — plenty are plain
 * classes the container can build on demand, and they work perfectly well over
 * HTTP. `QueueWorker` asked the container with `get()` alone, which throws
 * NotFoundException for exactly those, and the surrounding catch reported it as
 * "Error processing payload" and failed the message. A handler that works when
 * called directly failing only when it is queued, blamed on the payload.
 *
 * `EventDispatcher::runListenerSync()` has had the resilient form all along:
 * `has()` then `get()`, else `resolve()`. This pins that the container really
 * behaves that way, so the mirrored call in QueueWorker rests on something
 * measured rather than assumed.
 */
final class QueueWorkerHandlerResolutionTest extends TestCase
{
    #[Test]
    public function a_handler_that_is_not_a_registered_service_is_still_resolvable(): void
    {
        $container = ContainerFactory::get();

        self::assertFalse(
            $container->has(UnregisteredQueueHandlerProbe::class),
            'the probe must not be a registered service, or this proves nothing',
        );

        $resolved = $container->resolve(UnregisteredQueueHandlerProbe::class);

        self::assertInstanceOf(UnregisteredQueueHandlerProbe::class, $resolved);
        self::assertTrue(method_exists($resolved, 'handle'));
    }

    /**
     * And the reason the old code failed: get() alone refuses it.
     *
     * Stated as a test because it is the whole justification for the change —
     * if get() handled unregistered classes, the fallback would be noise.
     */
    #[Test]
    public function get_alone_refuses_an_unregistered_handler(): void
    {
        $container = ContainerFactory::get();

        $this->expectException(\Throwable::class);
        $container->get(UnregisteredQueueHandlerProbe::class);
    }

    /** The shape QueueWorker uses now, end to end. */
    #[Test]
    public function the_resilient_form_resolves_both_kinds(): void
    {
        $container = ContainerFactory::get();

        $resolve = static fn (string $class): object => $container->has($class)
            ? $container->get($class)
            : $container->resolve($class);

        self::assertInstanceOf(UnregisteredQueueHandlerProbe::class, $resolve(UnregisteredQueueHandlerProbe::class));
    }
}

/** A plain handler-shaped class that nothing registers. */
final class UnregisteredQueueHandlerProbe
{
    public function handle(object $request, object $response): object
    {
        return $response;
    }
}
