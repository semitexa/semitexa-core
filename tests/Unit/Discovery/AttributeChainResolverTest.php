<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeChainResolver;
use Semitexa\Core\Discovery\PayloadAttributeSchema;
use Semitexa\Core\Discovery\ResourceAttributeSchema;
use Semitexa\Core\Exception\ConfigurationException;

/**
 * Direct tests for {@see AttributeChainResolver}, the single implementation of
 * attribute-chain resolution after ep-slay-attribute-discovery (tk-ad-attribute-resolution).
 *
 * It replaced two copies inside AttributeDiscovery — one for payloads, one for
 * resources — that were written differently but proven behaviourally identical
 * over all 42 override shapes before being merged. These tests cover the axis
 * that mattered in that proof: which override values count as "declared" and
 * therefore win, and which are treated as absent and inherit.
 */
final class AttributeChainResolverTest extends TestCase
{
    #[Test]
    public function a_class_without_a_base_gets_schema_defaults(): void
    {
        $cache = [];
        $resolved = self::payloadResolver()->resolve(
            'P\\Solo',
            ['P\\Solo' => self::payloadMeta('Solo', ['path' => '/solo', 'accessType' => PayloadAccessType::Public])],
            $cache,
        );

        self::assertSame('/solo', $resolved['path']);
        self::assertSame(['GET'], $resolved['methods']);
        self::assertSame('Solo', $resolved['name']);
        self::assertSame(TransportType::Http, $resolved['transport']);
    }

    #[Test]
    public function a_child_inherits_every_key_it_does_not_declare(): void
    {
        $resolved = self::resolveChain(['transport' => TransportType::Sse, 'produces' => ['text/event-stream']], []);

        self::assertSame(TransportType::Sse, $resolved['transport']);
        self::assertSame(['text/event-stream'], $resolved['produces']);
    }

    #[Test]
    public function a_child_value_wins_over_the_base(): void
    {
        $resolved = self::resolveChain(['path' => '/base'], ['path' => '/child']);

        self::assertSame('/child', $resolved['path']);
    }

    #[Test]
    public function a_null_override_inherits_rather_than_blanking_the_base(): void
    {
        // This is the rule the whole design rests on: an attribute argument the
        // author never wrote arrives as null, and null must mean "inherit", not
        // "set to nothing". Getting this backwards would silently strip the
        // transport and access type off every subclassed payload.
        $resolved = self::resolveChain(['transport' => TransportType::Sse], ['transport' => null]);

        self::assertSame(TransportType::Sse, $resolved['transport']);
    }

    #[Test]
    public function falsy_but_declared_overrides_do_win(): void
    {
        // The counterpart to the rule above, and the trap a naive `if ($v)`
        // merge falls into: an empty tag list or an empty requirements map is a
        // real, deliberate declaration and must override the base.
        $resolved = self::resolveChain(['tags' => ['a'], 'requirements' => ['id' => '\d+']], ['tags' => [], 'requirements' => []]);

        self::assertSame([], $resolved['tags']);
        self::assertSame([], $resolved['requirements']);
    }

    #[Test]
    public function a_key_outside_the_schema_is_never_carried_across(): void
    {
        // mergeableKeys() is an allowlist. A child declaring something the
        // schema does not list must not smuggle it onto the resolved result.
        $resolved = self::resolveChain(['path' => '/base'], ['overrides' => 'P\\Something']);

        self::assertArrayNotHasKey('overrides', $resolved);
    }

    #[Test]
    public function the_chain_is_walked_transitively(): void
    {
        $meta = [
            'P\\Root' => self::payloadMeta('Root', ['path' => '/root', 'accessType' => PayloadAccessType::Public, 'transport' => TransportType::Sse]),
            'P\\Mid' => self::payloadMeta('Mid', ['base' => 'P\\Root', 'path' => '/mid']),
            'P\\Leaf' => self::payloadMeta('Leaf', ['base' => 'P\\Mid', 'name' => 'leaf']),
        ];

        $cache = [];
        $resolved = self::payloadResolver()->resolve('P\\Leaf', $meta, $cache);

        self::assertSame('/mid', $resolved['path'], 'nearest declaration wins');
        self::assertSame('leaf', $resolved['name']);
        self::assertSame(TransportType::Sse, $resolved['transport'], 'inherited across two hops');
    }

    #[Test]
    public function resolving_a_leaf_memoizes_every_ancestor_it_walked(): void
    {
        $meta = [
            'P\\Root' => self::payloadMeta('Root', ['path' => '/root', 'accessType' => PayloadAccessType::Public]),
            'P\\Leaf' => self::payloadMeta('Leaf', ['base' => 'P\\Root', 'path' => '/leaf']),
        ];

        $cache = [];
        self::payloadResolver()->resolve('P\\Leaf', $meta, $cache);

        self::assertArrayHasKey('P\\Root', $cache);
        self::assertArrayHasKey('P\\Leaf', $cache);
    }

    #[Test]
    public function a_base_discovery_never_saw_is_a_configuration_error_naming_the_family(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Request metadata missing for P\\Ghost');

        $cache = [];
        self::payloadResolver()->resolve(
            'P\\Orphan',
            ['P\\Orphan' => self::payloadMeta('Orphan', ['base' => 'P\\Ghost', 'path' => '/orphan'])],
            $cache,
        );
    }

    #[Test]
    public function the_resource_schema_reports_its_own_family_name(): void
    {
        // Same resolver, different schema — the message must say which discovery
        // pass failed, or a boot error sends you to the wrong half of discovery.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Response metadata missing for R\\Ghost');

        $cache = [];
        (new AttributeChainResolver(new ResourceAttributeSchema()))->resolve(
            'R\\Orphan',
            ['R\\Orphan' => ['class' => 'R\\Orphan', 'short' => 'Orphan', 'attr' => ['base' => 'R\\Ghost']]],
            $cache,
        );
    }

    #[Test]
    public function a_resource_without_a_handle_derives_one_from_its_short_name(): void
    {
        self::assertSame('about', ResourceAttributeSchema::defaultLayoutHandleFromShortName('AboutResponse'));
        self::assertSame('user-profile', ResourceAttributeSchema::defaultLayoutHandleFromShortName('UserProfileResponse'));
        self::assertSame('home', ResourceAttributeSchema::defaultLayoutHandleFromShortName('Home'));
    }

    private static function payloadResolver(): AttributeChainResolver
    {
        return new AttributeChainResolver(new PayloadAttributeSchema());
    }

    /**
     * @param  array<string, mixed> $base
     * @param  array<string, mixed> $child
     * @return array<string, mixed>
     */
    private static function resolveChain(array $base, array $child): array
    {
        $meta = [
            'P\\Base' => self::payloadMeta('Base', $base + ['path' => '/base', 'accessType' => PayloadAccessType::Public]),
            'P\\Child' => self::payloadMeta('Child', $child + ['base' => 'P\\Base']),
        ];

        $cache = [];

        return self::payloadResolver()->resolve('P\\Child', $meta, $cache);
    }

    /**
     * Discovery always passes a complete attribute map — every schema key
     * present, unset ones null — so fixtures are built the same way.
     *
     * @param  array<string, mixed> $declared
     * @return array{class: string, short: string, attr: array<string, mixed>}
     */
    private static function payloadMeta(string $short, array $declared): array
    {
        $attr = ['base' => null, 'overrides' => null];
        foreach ((new PayloadAttributeSchema())->mergeableKeys() as $key) {
            $attr[$key] = null;
        }

        return [
            'class' => 'P\\' . $short,
            'short' => $short,
            'attr' => array_replace($attr, $declared),
        ];
    }
}
