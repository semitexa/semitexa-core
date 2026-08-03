<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\PayloadPartRegistry;

/**
 * Direct tests for {@see PayloadPartRegistry}.
 *
 * Added by ep-slay-attribute-discovery (tk-ad-parts-collector). Until that task
 * the registry had no tests of its own: AttributeDiscovery carried a second,
 * dual-written copy of the same four maps and the same two lookups, so a defect
 * here would have had to be reproduced identically in both places to escape
 * notice. That copy is gone and this is now the only implementation of part
 * resolution in the framework — which makes these the tests that stand behind
 * every payload and resource trait composition at boot.
 */
final class PayloadPartRegistryTest extends TestCase
{
    #[Test]
    public function an_unknown_class_resolves_to_no_parts(): void
    {
        $registry = new PayloadPartRegistry();

        self::assertSame([], $registry->getPayloadPartsForClass(PartsFixtureLeafPayload::class));
        self::assertSame([], $registry->getResourcePartsForClass(PartsFixtureLeafResource::class));
    }

    #[Test]
    public function a_part_registered_on_a_class_resolves_for_that_class(): void
    {
        $registry = new PayloadPartRegistry();
        $registry->registerPayloadPart(PartsFixtureBasePayload::class, 'App\\Part\\Alpha');

        self::assertSame(
            ['App\\Part\\Alpha'],
            $registry->getPayloadPartsForClass(PartsFixtureBasePayload::class),
        );
    }

    #[Test]
    public function parts_are_inherited_through_real_php_subclassing(): void
    {
        $registry = new PayloadPartRegistry();
        $registry->registerPayloadPart(PartsFixtureBasePayload::class, 'App\\Part\\Alpha');

        self::assertSame(
            ['App\\Part\\Alpha'],
            $registry->getPayloadPartsForClass(PartsFixtureLeafPayload::class),
        );
    }

    #[Test]
    public function parts_are_inherited_through_the_declared_attribute_base_chain(): void
    {
        // The attribute `base:` chain is independent of PHP inheritance — two
        // unrelated classes can be linked by declaration alone, and the registry
        // must follow that link.
        $registry = new PayloadPartRegistry();
        $registry->registerPayloadPart('Declared\\Base', 'App\\Part\\Beta');
        $registry->registerPayloadBase('Declared\\Child', 'Declared\\Base');

        self::assertSame(
            ['App\\Part\\Beta'],
            $registry->getPayloadPartsForClass('Declared\\Child'),
        );
    }

    #[Test]
    public function the_attribute_base_chain_is_walked_transitively(): void
    {
        $registry = new PayloadPartRegistry();
        $registry->registerPayloadPart('Declared\\Root', 'App\\Part\\Gamma');
        $registry->registerPayloadBase('Declared\\Middle', 'Declared\\Root');
        $registry->registerPayloadBase('Declared\\Leaf', 'Declared\\Middle');

        self::assertSame(
            ['App\\Part\\Gamma'],
            $registry->getPayloadPartsForClass('Declared\\Leaf'),
        );
    }

    #[Test]
    public function every_matching_base_contributes_its_parts(): void
    {
        $registry = new PayloadPartRegistry();
        $registry->registerPayloadPart(PartsFixtureBasePayload::class, 'App\\Part\\Alpha');
        $registry->registerPayloadPart(PartsFixtureLeafPayload::class, 'App\\Part\\Delta');

        $parts = $registry->getPayloadPartsForClass(PartsFixtureLeafPayload::class);

        sort($parts);
        self::assertSame(['App\\Part\\Alpha', 'App\\Part\\Delta'], $parts);
    }

    #[Test]
    public function payload_and_resource_registrations_do_not_bleed_into_each_other(): void
    {
        // One registry instance backs both axes. Discovery registers payload
        // traits and resource traits against the same base names often enough
        // that a shared bucket would look correct in a smoke test and be wrong
        // in production.
        $registry = new PayloadPartRegistry();
        $registry->registerPayloadPart('Shared\\Base', 'App\\Part\\PayloadOnly');
        $registry->registerResourcePart('Shared\\Base', 'App\\Part\\ResourceOnly');

        self::assertSame(['App\\Part\\PayloadOnly'], $registry->getPayloadPartsForClass('Shared\\Base'));
        self::assertSame(['App\\Part\\ResourceOnly'], $registry->getResourcePartsForClass('Shared\\Base'));
    }

    #[Test]
    public function the_payload_and_resource_base_chains_are_kept_apart(): void
    {
        $registry = new PayloadPartRegistry();
        $registry->registerPayloadPart('Declared\\Base', 'App\\Part\\PayloadOnly');
        $registry->registerResourceBase('Declared\\Child', 'Declared\\Base');

        // The child is linked on the RESOURCE axis only, so the payload lookup
        // must not follow it.
        self::assertSame([], $registry->getPayloadPartsForClass('Declared\\Child'));
    }

    #[Test]
    public function resource_parts_resolve_through_subclassing_too(): void
    {
        $registry = new PayloadPartRegistry();
        $registry->registerResourcePart(PartsFixtureBaseResource::class, 'App\\Part\\Epsilon');

        self::assertSame(
            ['App\\Part\\Epsilon'],
            $registry->getResourcePartsForClass(PartsFixtureLeafResource::class),
        );
    }
}

class PartsFixtureBasePayload
{
}

class PartsFixtureLeafPayload extends PartsFixtureBasePayload
{
}

class PartsFixtureBaseResource
{
}

class PartsFixtureLeafResource extends PartsFixtureBaseResource
{
}
