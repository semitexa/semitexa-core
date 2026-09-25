<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\ExecutionContext;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Request;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * SemitexaContainer caches, per class, which injection-map entries a clone
 * needs and the reflection handle used to write each one. The cache holds
 * metadata only: every get() must still hand out a fresh clone carrying the
 * CURRENT execution context and a fresh nested prototype clone, never a value
 * captured on an earlier call.
 */
final class SemitexaContainerClonePlanCacheTest extends TestCase
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
    public function repeated_gets_inject_the_current_context_not_a_cached_one(): void
    {
        $container = $this->buildContainer();

        $requestA = new Request('GET', '/a', [], [], [], [], []);
        $container->setExecutionContext(new ExecutionContext(request: $requestA));
        $first = $container->get(ClonePlanTarget::class);

        $requestB = new Request('GET', '/b', [], [], [], [], []);
        $container->setExecutionContext(new ExecutionContext(request: $requestB));
        $second = $container->get(ClonePlanTarget::class);

        self::assertNotSame($first, $second);
        self::assertSame($requestA, $first->request());
        self::assertSame($requestB, $second->request());
    }

    #[Test]
    public function nested_prototype_is_cloned_afresh_and_its_own_mutables_are_injected_every_time(): void
    {
        $container = $this->buildContainer();
        $request = new Request('GET', '/n', [], [], [], [], []);
        $container->setExecutionContext(new ExecutionContext(request: $request));

        $first = $container->get(ClonePlanTarget::class);
        $second = $container->get(ClonePlanTarget::class);

        self::assertNotSame($first->nested, $second->nested);
        self::assertSame($request, $first->nested->request);
        self::assertSame($request, $second->nested->request);

        // The prototypes themselves are never written to.
        $store = (new ReflectionProperty(SemitexaContainer::class, 'instanceStore'))->getValue($container);
        self::assertFalse((new ReflectionProperty(ClonePlanTarget::class, 'request'))->isInitialized($store->prototypes[ClonePlanTarget::class]));
        self::assertFalse((new ReflectionProperty(ClonePlanNested::class, 'request'))->isInitialized($store->prototypes[ClonePlanNested::class]));
    }

    #[Test]
    public function a_class_with_no_clone_time_injections_is_returned_untouched(): void
    {
        $container = $this->buildContainer();

        $plain = $container->get(ClonePlanPlain::class);

        self::assertInstanceOf(ClonePlanPlain::class, $plain);
        self::assertSame('untouched', $plain->value);
    }

    private function buildContainer(): SemitexaContainer
    {
        $container = new SemitexaContainer();

        $this->mutate($container, 'instanceStore', function (InstanceStore $store): void {
            foreach ([ClonePlanTarget::class, ClonePlanNested::class, ClonePlanPlain::class] as $class) {
                $store->prototypes[$class] = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            }
        });
        $this->mutate($container, 'typeMap', function (TypeMap $map): void {
            foreach ([ClonePlanTarget::class, ClonePlanNested::class, ClonePlanPlain::class] as $class) {
                $map->registeredClasses[$class] = true;
                $map->executionScoped[$class] = true;
            }
        });
        $this->mutate($container, 'injectionMap', function (InjectionMap $map): void {
            $map->injections[ClonePlanTarget::class] = [
                'request' => ['kind' => 'mutable', 'type' => Request::class],
                'nested' => ['kind' => 'mutable', 'type' => ClonePlanNested::class],
                'ignored' => ['kind' => 'readonly', 'type' => \stdClass::class],
            ];
            $map->injections[ClonePlanNested::class] = [
                'request' => ['kind' => 'mutable', 'type' => Request::class],
            ];
        });

        return $container;
    }

    private function mutate(SemitexaContainer $container, string $field, callable $mutator): void
    {
        $mutator((new ReflectionProperty(SemitexaContainer::class, $field))->getValue($container));
    }
}

/** @internal */
final class ClonePlanTarget
{
    // Private: the cached handle must still reach non-public properties.
    private Request $request;
    public ClonePlanNested $nested;

    public function request(): Request
    {
        return $this->request;
    }
}

/** @internal */
final class ClonePlanNested
{
    public Request $request;
}

/** @internal */
final class ClonePlanPlain
{
    public string $value = 'untouched';
}
