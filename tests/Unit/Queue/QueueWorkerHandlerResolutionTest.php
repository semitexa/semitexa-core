<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;

/**
 * A queued handler is resolved through the container's allowlist, and only it.
 *
 * `QueueWorker` briefly gained a `has() ? get() : resolve()` fallback, mirroring
 * `EventDispatcher::runListenerSync()`, on the belief that a payload handler
 * need not be a registered service. That belief was wrong, and these tests are
 * the measurement that settles it.
 *
 * `ServiceRegistrationPhase` registers every discovered payload handler by
 * concrete class — no `#[AsService]` required — so `get()` already answers for
 * all of them. What `has() === false` actually means is that the class was not
 * approved for THIS boot: a disabled module, a deleted handler, a message older
 * than the deployment, or a class name that never came from `QueueDispatcher`.
 * `resolve()` there would instantiate any autoloadable class with a `handle()`
 * method that a queue message happens to name.
 */
final class QueueWorkerHandlerResolutionTest extends TestCase
{
    /**
     * The fact the reverted change got wrong: every ACTIVE handler is already
     * reachable through get(), so a fallback buys nothing.
     *
     * Read from AttributeDiscovery's handler registry, not from a raw
     * ClassDiscovery sweep. The two differ exactly where it matters: discovery
     * filters #[AsPayloadHandler] classes through ModuleRegistry::isClassActive(),
     * and ServiceRegistrationPhase registers that filtered set. A raw sweep
     * would list a disabled module's handlers, which the container rightly does
     * not register — so the assertion would fail in precisely the supported
     * configuration the production comment is about.
     */
    #[Test]
    public function every_active_payload_handler_is_a_registered_service(): void
    {
        $container = ContainerFactory::get();
        $discovery = $container->get(AttributeDiscovery::class);
        self::assertInstanceOf(AttributeDiscovery::class, $discovery);

        $handlers = $discovery->getHandlerRegistry()->getHandlerClassNames();

        self::assertNotEmpty($handlers, 'nothing discovered would make this vacuous');

        $unregistered = array_values(array_filter(
            $handlers,
            static fn (string $class): bool => !$container->has($class),
        ));

        self::assertSame([], $unregistered, 'a fallback would only ever fire for a handler nothing approved');
    }

    /**
     * And the allowlist has teeth: a handler-shaped class nothing discovered is
     * refused rather than built. This is the property the queue depends on —
     * the class name arrives inside a broker message.
     */
    #[Test]
    public function a_handler_shaped_class_that_was_never_discovered_is_refused(): void
    {
        $container = ContainerFactory::get();

        self::assertFalse($container->has(UnregisteredQueueHandlerProbe::class));

        $this->expectException(\Throwable::class);
        $container->get(UnregisteredQueueHandlerProbe::class);
    }
}

/** A plain handler-shaped class that nothing registers — and must stay refused. */
final class UnregisteredQueueHandlerProbe
{
    public function handle(object $request, object $response): object
    {
        return $response;
    }
}
