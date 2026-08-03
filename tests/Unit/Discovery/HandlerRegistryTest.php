<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\HandlerRegistry;

/**
 * Direct tests for {@see HandlerRegistry}.
 *
 * Added by ep-slay-attribute-discovery (tk-ad-handler-collector), which deleted
 * the third and last shadow copy inside AttributeDiscovery — a second map with
 * the same composite key, a `findHandlersByPayloadAndResource` whose body was
 * byte-identical to `findHandlers`, and a dual-write the original author had
 * even labelled as such. Like PayloadPartRegistry before it, this class had no
 * tests of its own precisely because the duplicate quietly stood behind it.
 * It is now the only place handler lookup happens.
 */
final class HandlerRegistryTest extends TestCase
{
    #[Test]
    public function nothing_matches_an_empty_registry(): void
    {
        self::assertSame([], (new HandlerRegistry())->findHandlers(BasePayload::class, BaseResource::class));
        self::assertSame([], (new HandlerRegistry())->getHandlerClassNames());
        self::assertSame([], (new HandlerRegistry())->payloadClasses());
    }

    #[Test]
    public function a_null_response_class_can_never_match(): void
    {
        // A route with no resolved resource has nothing for a handler to
        // produce, so matching must short-circuit rather than fall through to
        // the subclass walk.
        $registry = self::registryWith(BasePayload::class, BaseResource::class);

        self::assertSame([], $registry->findHandlers(BasePayload::class, null));
    }

    #[Test]
    public function an_exact_payload_and_resource_pair_matches(): void
    {
        $registry = self::registryWith(BasePayload::class, BaseResource::class);

        $found = $registry->findHandlers(BasePayload::class, BaseResource::class);

        self::assertCount(1, $found);
        self::assertSame('App\\Handler\\H1', $found[0]['class']);
    }

    #[Test]
    public function a_subclassed_resource_matches_a_handler_registered_on_its_base(): void
    {
        // This is the rule that makes module overriding work: a route resolves
        // to the project's LeafResource while the handler was declared against
        // the module's BaseResource.
        $registry = self::registryWith(BasePayload::class, BaseResource::class);

        self::assertCount(1, $registry->findHandlers(BasePayload::class, LeafResource::class));
    }

    #[Test]
    public function a_subclassed_payload_matches_a_handler_registered_on_its_base(): void
    {
        $registry = self::registryWith(BasePayload::class, BaseResource::class);

        self::assertCount(1, $registry->findHandlers(LeafPayload::class, BaseResource::class));
    }

    #[Test]
    public function the_subclass_rule_does_not_run_backwards(): void
    {
        // Registered on the LEAF, queried with the BASE. A base resource is not
        // a leaf, so this must not match — an inverted is_subclass_of here would
        // hand every route the most specific handler in the system.
        $registry = self::registryWith(LeafPayload::class, LeafResource::class);

        self::assertSame([], $registry->findHandlers(BasePayload::class, BaseResource::class));
    }

    #[Test]
    public function an_unrelated_pair_does_not_match(): void
    {
        $registry = self::registryWith(BasePayload::class, BaseResource::class);

        self::assertSame([], $registry->findHandlers(OtherPayload::class, BaseResource::class));
        self::assertSame([], $registry->findHandlers(BasePayload::class, OtherResource::class));
    }

    #[Test]
    public function several_handlers_on_one_pair_are_all_returned(): void
    {
        $registry = new HandlerRegistry();
        $registry->register(BasePayload::class, BaseResource::class, self::meta('App\\Handler\\H1'));
        $registry->register(BasePayload::class, BaseResource::class, self::meta('App\\Handler\\H2'));

        $found = $registry->findHandlers(BasePayload::class, BaseResource::class);

        self::assertSame(['App\\Handler\\H1', 'App\\Handler\\H2'], array_column($found, 'class'));
    }

    #[Test]
    public function handler_metadata_is_retrievable_by_class(): void
    {
        $registry = self::registryWith(BasePayload::class, BaseResource::class);

        self::assertSame('sync', $registry->getHandlerByClass('App\\Handler\\H1')['execution'] ?? null);
        self::assertNull($registry->getHandlerByClass('App\\Handler\\Nope'));
    }

    #[Test]
    public function payload_classes_are_distinct_across_resources(): void
    {
        // The boot guard walks this list. One payload served by three resources
        // must appear once, or the guard reports the same missing route thrice.
        $registry = new HandlerRegistry();
        $registry->register(BasePayload::class, BaseResource::class, self::meta('App\\Handler\\H1'));
        $registry->register(BasePayload::class, LeafResource::class, self::meta('App\\Handler\\H2'));
        $registry->register(OtherPayload::class, OtherResource::class, self::meta('App\\Handler\\H3'));

        $payloads = $registry->payloadClasses();

        sort($payloads);
        self::assertSame([BasePayload::class, OtherPayload::class], $payloads);
    }

    private static function registryWith(string $payload, string $resource): HandlerRegistry
    {
        $registry = new HandlerRegistry();
        $registry->register($payload, $resource, self::meta('App\\Handler\\H1'));

        return $registry;
    }

    /**
     * @return array{class: string, execution: string, transport: ?string, queue: ?string, priority: int}
     */
    private static function meta(string $class): array
    {
        return [
            'class' => $class,
            'execution' => 'sync',
            'transport' => null,
            'queue' => null,
            'priority' => 0,
        ];
    }
}

class BasePayload
{
}

class LeafPayload extends BasePayload
{
}

class OtherPayload
{
}

class BaseResource
{
}

class LeafResource extends BaseResource
{
}

class OtherResource
{
}
