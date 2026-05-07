<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\PropertyInjector;

/**
 * Covers the runtime property-injection channel used by console commands,
 * resource response DTOs, and other objects that live outside the DI graph.
 *
 * Structural rules are strict — protected visibility, named class/interface
 * type. Nullable types are TREATED AS OPTIONAL: the framework's resource
 * response classes (`JsonResourceResponse`, `JsonLdResourceResponse`,
 * `GraphqlResourceResponse`) declare their service slots as `?Type = null`
 * so tests can construct them without the container and bind services
 * manually via `bindServices()`. PropertyInjector respects that contract:
 * a present binding is injected, an absent one leaves the default in
 * place. Required dependencies remain non-nullable and throw when
 * unbound.
 */
class PropertyInjectorTest extends TestCase
{
    protected function setUp(): void
    {
        PropertyInjector::clearCache();
    }

    public function test_inject_populates_readonly_property_from_container(): void
    {
        $dep = new PropertyInjectorTest_Dep();
        $target = new PropertyInjectorTest_Target();

        PropertyInjector::inject($target, new PropertyInjectorTest_Container([
            PropertyInjectorTest_Dep::class => $dep,
        ]));

        $this->assertSame($dep, $target->exposeDep());
    }

    public function test_inject_throws_when_binding_missing(): void
    {
        $this->expectException(InjectionException::class);
        $this->expectExceptionMessageMatches('/container has no binding/');
        PropertyInjector::inject(
            new PropertyInjectorTest_Target(),
            new PropertyInjectorTest_Container([]),
        );
    }

    public function test_public_property_rejected(): void
    {
        $this->expectException(InjectionException::class);
        $this->expectExceptionMessageMatches('/must be protected/');
        PropertyInjector::metadata(PropertyInjectorTest_PublicProp::class);
    }

    public function test_private_property_rejected(): void
    {
        $this->expectException(InjectionException::class);
        $this->expectExceptionMessageMatches('/must be protected/');
        PropertyInjector::metadata(PropertyInjectorTest_PrivateProp::class);
    }

    public function test_builtin_type_rejected(): void
    {
        $this->expectException(InjectionException::class);
        $this->expectExceptionMessageMatches('/must have a class or interface type/');
        PropertyInjector::metadata(PropertyInjectorTest_BuiltinProp::class);
    }

    public function test_nullable_type_is_optional_and_skipped_when_unbound(): void
    {
        $target = new PropertyInjectorTest_NullableProp();

        // Container has nothing for the dependency — optional injection
        // must NOT throw and must leave the default value in place.
        PropertyInjector::inject($target, new PropertyInjectorTest_Container([]));

        $this->assertNull(
            $target->exposeDep(),
            'Nullable injected properties are optional; absent bindings leave the default.',
        );
    }

    public function test_nullable_type_is_injected_when_bound(): void
    {
        $dep = new PropertyInjectorTest_Dep();
        $target = new PropertyInjectorTest_NullableProp();

        PropertyInjector::inject($target, new PropertyInjectorTest_Container([
            PropertyInjectorTest_Dep::class => $dep,
        ]));

        $this->assertSame(
            $dep,
            $target->exposeDep(),
            'Nullable injected properties are still wired when a binding exists — '
            . '"optional" means absence is tolerated, not that injection is skipped.',
        );
    }

    public function test_required_type_still_throws_when_unbound(): void
    {
        // Pins the asymmetry: non-nullable means required. The optional
        // semantic introduced for nullable types must not bleed into
        // non-nullable ones.
        $this->expectException(InjectionException::class);
        $this->expectExceptionMessageMatches('/container has no binding/');
        PropertyInjector::inject(
            new PropertyInjectorTest_Target(),
            new PropertyInjectorTest_Container([]),
        );
    }

    public function test_metadata_is_cached_per_class(): void
    {
        $first = PropertyInjector::metadata(PropertyInjectorTest_Target::class);
        $second = PropertyInjector::metadata(PropertyInjectorTest_Target::class);
        $this->assertSame($first, $second);
        $this->assertSame(
            ['dep' => ['type' => PropertyInjectorTest_Dep::class, 'optional' => false]],
            $first,
        );
    }

    public function test_properties_without_attribute_ignored(): void
    {
        $dep = new PropertyInjectorTest_Dep();
        $target = new PropertyInjectorTest_MixedTarget();

        PropertyInjector::inject($target, new PropertyInjectorTest_Container([
            PropertyInjectorTest_Dep::class => $dep,
        ]));

        $this->assertSame($dep, $target->exposeAnnotated());
        $this->assertNull($target->exposePlain());
    }
}

class PropertyInjectorTest_Dep {}

class PropertyInjectorTest_Target
{
    #[InjectAsReadonly]
    protected PropertyInjectorTest_Dep $dep;

    public function exposeDep(): PropertyInjectorTest_Dep
    {
        return $this->dep;
    }
}

class PropertyInjectorTest_MixedTarget
{
    #[InjectAsReadonly]
    protected PropertyInjectorTest_Dep $annotated;

    protected ?PropertyInjectorTest_Dep $plain = null;

    public function exposeAnnotated(): PropertyInjectorTest_Dep
    {
        return $this->annotated;
    }

    public function exposePlain(): ?PropertyInjectorTest_Dep
    {
        return $this->plain;
    }
}

class PropertyInjectorTest_PublicProp
{
    #[InjectAsReadonly]
    public PropertyInjectorTest_Dep $dep;
}

class PropertyInjectorTest_PrivateProp
{
    #[InjectAsReadonly]
    private PropertyInjectorTest_Dep $dep;
}

class PropertyInjectorTest_BuiltinProp
{
    #[InjectAsReadonly]
    protected string $dep;
}

class PropertyInjectorTest_NullableProp
{
    #[InjectAsReadonly]
    protected ?PropertyInjectorTest_Dep $dep = null;

    public function exposeDep(): ?PropertyInjectorTest_Dep
    {
        return $this->dep;
    }
}

final class PropertyInjectorTest_Container implements ContainerInterface
{
    /** @param array<class-string, object> $bindings */
    public function __construct(private array $bindings) {}

    public function get(string $id): object
    {
        if (!isset($this->bindings[$id])) {
            throw new class("No binding: {$id}") extends \RuntimeException implements NotFoundExceptionInterface {};
        }
        return $this->bindings[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }
}
