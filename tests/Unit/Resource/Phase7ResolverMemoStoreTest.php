<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Memo\ResolverMemoStore;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingPreferencesResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\RecordingProfileResolver;

/**
 * Phase 7: pure unit tests of the `ResolverMemoStore` value object.
 * The store is keyed by
 *
 *   resolverClass | parentUrn | fieldName | parentClass | targetClass
 *
 * The pipeline integration is exercised in
 * `Phase7ResolverMemoisationPipelineTest`; this file covers the
 * key derivation rules and storage semantics in isolation.
 */
final class Phase7ResolverMemoStoreTest extends TestCase
{
    private function profileField(): ResourceFieldMetadata
    {
        return new ResourceFieldMetadata(
            name:          'profile',
            kind:          ResourceFieldKind::RefOne,
            nullable:      true,
            target:        ProfileResource::class,
            include:       'profile',
            expandable:    true,
            resolverClass: RecordingProfileResolver::class,
        );
    }

    private function preferencesField(): ResourceFieldMetadata
    {
        return new ResourceFieldMetadata(
            name:          'preferences',
            kind:          ResourceFieldKind::RefOne,
            nullable:      true,
            target:        PreferencesResource::class,
            include:       'preferences',
            expandable:    true,
            resolverClass: RecordingPreferencesResolver::class,
        );
    }

    #[Test]
    public function empty_store_has_no_entries(): void
    {
        $store = new ResolverMemoStore();
        self::assertSame(0, $store->size());
        self::assertFalse($store->has('whatever'));
    }

    #[Test]
    public function set_then_has_then_get_round_trips(): void
    {
        $store    = new ResolverMemoStore();
        $identity = ResourceIdentity::of('phase6e_customer', '7');
        $key      = ResolverMemoStore::keyFromField(
            $this->profileField(),
            $identity,
            'CustomerClass',
        );

        $store->set($key, null);
        self::assertTrue($store->has($key));
        self::assertNull($store->get($key));
        self::assertSame(1, $store->size());
    }

    #[Test]
    public function get_unknown_key_throws_logic_exception(): void
    {
        $store = new ResolverMemoStore();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/cannot get unknown key/');
        $store->get('definitely-not-there');
    }

    #[Test]
    public function key_changes_when_resolver_class_differs(): void
    {
        $identity = ResourceIdentity::of('customer', '1');
        $a = ResolverMemoStore::formatKey('Resolver\\A', $identity, 'profile', 'Customer', null);
        $b = ResolverMemoStore::formatKey('Resolver\\B', $identity, 'profile', 'Customer', null);
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function key_changes_when_parent_urn_differs(): void
    {
        $a = ResolverMemoStore::formatKey('Resolver\\A', ResourceIdentity::of('customer', '1'), 'profile', 'Customer', null);
        $b = ResolverMemoStore::formatKey('Resolver\\A', ResourceIdentity::of('customer', '2'), 'profile', 'Customer', null);
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function key_changes_when_field_name_differs(): void
    {
        $identity = ResourceIdentity::of('customer', '1');
        $a = ResolverMemoStore::formatKey('Resolver\\A', $identity, 'profile',   'Customer', null);
        $b = ResolverMemoStore::formatKey('Resolver\\A', $identity, 'addresses', 'Customer', null);
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function key_changes_when_parent_class_differs(): void
    {
        $identity = ResourceIdentity::of('customer', '1');
        $a = ResolverMemoStore::formatKey('Resolver\\A', $identity, 'profile', 'CustomerA', null);
        $b = ResolverMemoStore::formatKey('Resolver\\A', $identity, 'profile', 'CustomerB', null);
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function key_changes_when_target_class_differs(): void
    {
        $identity = ResourceIdentity::of('customer', '1');
        $a = ResolverMemoStore::formatKey('Resolver\\A', $identity, 'profile', 'Customer', 'TargetA');
        $b = ResolverMemoStore::formatKey('Resolver\\A', $identity, 'profile', 'Customer', 'TargetB');
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function key_from_field_matches_format_key(): void
    {
        $field    = $this->profileField();
        $identity = ResourceIdentity::of('phase6e_customer', '42');

        self::assertSame(
            ResolverMemoStore::formatKey(
                resolverClass:  RecordingProfileResolver::class,
                parentIdentity: $identity,
                fieldName:      'profile',
                parentClass:    'CustomerXYZ',
                targetClass:    ProfileResource::class,
            ),
            ResolverMemoStore::keyFromField($field, $identity, 'CustomerXYZ'),
        );
    }

    #[Test]
    public function two_separate_stores_do_not_share_state(): void
    {
        $a = new ResolverMemoStore();
        $b = new ResolverMemoStore();
        $key = ResolverMemoStore::formatKey('R', ResourceIdentity::of('x', '1'), 'f', 'P', null);
        $a->set($key, null);
        self::assertTrue($a->has($key));
        self::assertFalse($b->has($key));
    }

    #[Test]
    public function set_overwrites_existing_value_for_same_key(): void
    {
        $store    = new ResolverMemoStore();
        $key      = ResolverMemoStore::formatKey('R', ResourceIdentity::of('x', '1'), 'f', 'P', null);
        $first    = new PreferencesResource(id: 'a', theme: 'dark');
        $second   = new PreferencesResource(id: 'b', theme: 'light');

        $store->set($key, $first);
        $store->set($key, $second);

        self::assertSame($second, $store->get($key));
        self::assertSame(1, $store->size(), 'overwrites must not grow the store.');
    }

    #[Test]
    public function field_without_resolver_class_is_a_logic_error_for_key_from_field(): void
    {
        // The pipeline never asks for a key on a field without
        // resolverClass, but we pin the assertion here for safety.
        $field = new ResourceFieldMetadata(
            name:          'profile',
            kind:          ResourceFieldKind::RefOne,
            nullable:      true,
            target:        ProfileResource::class,
            include:       'profile',
            expandable:    true,
            resolverClass: null,
        );

        $this->expectException(\AssertionError::class);
        ResolverMemoStore::keyFromField($field, ResourceIdentity::of('x', '1'), 'P');
    }

    #[Test]
    public function preferences_and_profile_keys_for_same_parent_urn_do_not_collide(): void
    {
        // The fixture identity '7' for `phase6e_customer` is used by
        // BOTH the customer-profile bucket and the (later)
        // profile-preferences bucket — a single id might surface as
        // both a parent urn (customer:7) and as a child sort target
        // (profile:7-profile). The memo MUST treat the two as
        // separate keys.
        $customerIdentity = ResourceIdentity::of('phase6e_customer', '7');
        $profileIdentity  = ResourceIdentity::of('profile', '7-profile');

        $customerProfileKey = ResolverMemoStore::keyFromField(
            $this->profileField(),
            $customerIdentity,
            'CustomerXYZ',
        );
        $profilePreferencesKey = ResolverMemoStore::keyFromField(
            $this->preferencesField(),
            $profileIdentity,
            'ProfileXYZ',
        );

        self::assertNotSame($customerProfileKey, $profilePreferencesKey);
    }
}
