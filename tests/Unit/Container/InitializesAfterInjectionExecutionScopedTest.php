<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Contract\InitializesAfterInjectionInterface;

final class ScopedInitDependency
{
    public function __construct(public string $value = 'from-the-clone')
    {
    }
}

final class ScopedInitTarget implements InitializesAfterInjectionInterface
{
    /** Populated per execution, on the clone — never on the boot prototype. */
    protected ScopedInitDependency $dep;

    public string $derived = '';

    public int $initializeCalls = 0;

    public function initialize(): void
    {
        // Reading a per-execution dependency is the whole point: the contract
        // says every injected property is populated before this runs.
        $this->derived = 'derived:' . $this->dep->value;
        $this->initializeCalls++;
    }
}

/**
 * An execution-scoped class is only whole once it has been cloned.
 *
 * The boot prototype carries the readonly half; #[InjectAsMutable] and factory
 * properties are attached per execution, on the clone. Initializing the
 * prototype therefore ran initialize() against uninitialized typed properties
 * at boot and never ran it for the object anyone actually received — which is
 * the opposite of what the interface promises. Raised in review of
 * semitexa-core#129.
 */
final class InitializesAfterInjectionExecutionScopedTest extends TestCase
{
    #[Test]
    public function initialize_runs_on_the_clone_with_its_mutable_dependency_in_place(): void
    {
        $container = $this->buildContainer();

        $instance = $container->get(ScopedInitTarget::class);

        self::assertInstanceOf(ScopedInitTarget::class, $instance);
        self::assertSame('derived:from-the-clone', $instance->derived, 'initialize() must see the injected dependency');
        self::assertSame(1, $instance->initializeCalls);
    }

    #[Test]
    public function every_execution_gets_its_own_initialized_object_and_the_prototype_stays_untouched(): void
    {
        $container = $this->buildContainer();

        $first = $container->get(ScopedInitTarget::class);
        $second = $container->get(ScopedInitTarget::class);

        self::assertNotSame($first, $second);
        self::assertSame(1, $first->initializeCalls, 'one execution, one initialize');
        self::assertSame(1, $second->initializeCalls);

        $store = (new ReflectionProperty(SemitexaContainer::class, 'instanceStore'))->getValue($container);
        self::assertSame(
            0,
            $store->prototypes[ScopedInitTarget::class]->initializeCalls,
            'the prototype is half an object; initializing it would run against properties that do not exist yet',
        );
    }

    private function buildContainer(): SemitexaContainer
    {
        $container = new SemitexaContainer();

        $prototype = (new \ReflectionClass(ScopedInitTarget::class))->newInstanceWithoutConstructor();

        $this->replacePrivate($container, 'instanceStore', function (InstanceStore $store) use ($prototype): void {
            $store->prototypes[ScopedInitTarget::class] = $prototype;
            // Also execution-scoped: a mutable property is resolved from the
            // execution context or a prototype, which is exactly the shape
            // that made the old ordering fail.
            $store->prototypes[ScopedInitDependency::class] = new ScopedInitDependency();
        });

        $this->replacePrivate($container, 'typeMap', function (TypeMap $map): void {
            $map->registeredClasses[ScopedInitTarget::class] = true;
            $map->executionScoped[ScopedInitTarget::class] = true;
            $map->registeredClasses[ScopedInitDependency::class] = true;
            $map->executionScoped[ScopedInitDependency::class] = true;
        });

        $this->replacePrivate($container, 'injectionMap', function (InjectionMap $map): void {
            $map->injections[ScopedInitTarget::class] = [
                'dep' => ['kind' => 'mutable', 'type' => ScopedInitDependency::class],
            ];
        });

        return $container;
    }

    private function replacePrivate(SemitexaContainer $container, string $field, callable $mutator): void
    {
        $prop = new ReflectionProperty(SemitexaContainer::class, $field);
        $mutator($prop->getValue($container));
    }
}
