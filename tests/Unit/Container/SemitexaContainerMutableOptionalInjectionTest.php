<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * Coverage for the mutable optional-skip branch in
 * SemitexaContainer::injectMutableProperties(): an `#[InjectAsMutable(optional:
 * true)]` property with no execution-context value and no prototype must be
 * left uninitialized (soft dependency), while a required one must still throw.
 */
final class SemitexaContainerMutableOptionalInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        CoroutineLocal::resetCliStore();
    }

    protected function tearDown(): void
    {
        CoroutineLocal::resetCliStore();
    }

    #[Test]
    public function optional_mutable_property_is_skipped_when_nothing_can_satisfy_it(): void
    {
        $container = $this->buildContainer(optional: true);

        $instance = $container->get(MutableOptionalTarget::class);

        $ref = new ReflectionProperty(MutableOptionalTarget::class, 'dep');
        $this->assertFalse(
            $ref->isInitialized($instance),
            'Optional mutable dependency must stay uninitialized, not throw.',
        );
    }

    #[Test]
    public function required_mutable_property_still_throws_when_nothing_can_satisfy_it(): void
    {
        $container = $this->buildContainer(optional: false);

        $this->expectException(InjectionException::class);
        $container->get(MutableOptionalTarget::class);
    }

    private function buildContainer(bool $optional): SemitexaContainer
    {
        $container = new SemitexaContainer();

        $prototype = (new \ReflectionClass(MutableOptionalTarget::class))->newInstanceWithoutConstructor();

        $this->replacePrivate($container, 'instanceStore', function (InstanceStore $store) use ($prototype): void {
            $store->prototypes[MutableOptionalTarget::class] = $prototype;
        });

        $this->replacePrivate($container, 'typeMap', function (TypeMap $map): void {
            $map->registeredClasses[MutableOptionalTarget::class] = true;
            $map->executionScoped[MutableOptionalTarget::class] = true;
        });

        $this->replacePrivate($container, 'injectionMap', function (InjectionMap $map) use ($optional): void {
            $map->injections[MutableOptionalTarget::class] = [
                'dep' => ['kind' => 'mutable', 'type' => DanglingMutableDep::class, 'optional' => $optional],
            ];
        });

        return $container;
    }

    private function replacePrivate(SemitexaContainer $container, string $field, callable $mutator): void
    {
        $prop = new ReflectionProperty(SemitexaContainer::class, $field);
        $prop->setAccessible(true);
        $mutator($prop->getValue($container));
    }
}

/**
 * Test fixture: execution-scoped prototype with one mutable dependency that
 * nothing in the container can satisfy (no context value, no prototype).
 *
 * @internal
 */
final class MutableOptionalTarget
{
    public DanglingMutableDep $dep;
}

/** @internal */
final class DanglingMutableDep
{
}
