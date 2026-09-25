<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\ContractFactory;
use Semitexa\Core\Container\GraphBuilder;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Contract\ContractFactoryInterface;
use Semitexa\Core\Contract\InitializesAfterInjectionInterface;

interface FactoryFixtureChannel
{
}

interface FactoryFactoryFixtureChannel extends ContractFactoryInterface
{
}

enum FactoryFixtureChannelKind: string
{
    case Scoped = 'scoped';
    case Worker = 'worker';
}

final class FactoryFixtureExecutionState
{
}

final class FactoryFixtureScopedChannel implements FactoryFixtureChannel, InitializesAfterInjectionInterface
{
    protected FactoryFixtureExecutionState $state;

    public bool $initialized = false;

    public ?string $written = null;

    public function state(): FactoryFixtureExecutionState
    {
        return $this->state;
    }

    public function initialize(): void
    {
        $this->initialized = true;
    }
}

final class FactoryFixtureWorkerChannel implements FactoryFixtureChannel
{
}

/**
 * #[InjectAsFactory] get()/getDefault() handed out the execution-scoped
 * PROTOTYPE itself: one object shared by every request, never cloned, its
 * #[InjectAsMutable] properties never injected and initialize() never run.
 * State written to it leaked into every later request and into every
 * container->get() clone of that class.
 */
final class ContractFactoryExecutionScopedTest extends TestCase
{
    #[Test]
    public function execution_scoped_implementation_is_a_fresh_injected_initialized_clone_per_call(): void
    {
        [$container, $factory, $prototype] = $this->build();

        $first = $factory->get(FactoryFixtureChannelKind::Scoped);
        $second = $factory->get(FactoryFixtureChannelKind::Scoped);

        self::assertInstanceOf(FactoryFixtureScopedChannel::class, $first);
        self::assertNotSame($prototype, $first);
        self::assertNotSame($first, $second);
        self::assertInstanceOf(FactoryFixtureExecutionState::class, $first->state());
        self::assertTrue($first->initialized);
        self::assertInstanceOf(FactoryFixtureScopedChannel::class, $factory->getDefault());
        self::assertNotSame($prototype, $factory->getDefault());
    }

    #[Test]
    public function state_written_through_the_factory_does_not_leak_into_later_resolutions(): void
    {
        [$container, $factory, $prototype] = $this->build();

        $channel = $factory->get(FactoryFixtureChannelKind::Scoped);
        self::assertInstanceOf(FactoryFixtureScopedChannel::class, $channel);
        $channel->written = 'request-1';

        $later = $container->get(FactoryFixtureScopedChannel::class);
        self::assertInstanceOf(FactoryFixtureScopedChannel::class, $later);
        self::assertNull($later->written);
        self::assertNull($prototype->written);
    }

    #[Test]
    public function worker_scoped_implementation_stays_the_shared_instance(): void
    {
        [$container, $factory] = $this->build();

        self::assertSame(
            $container->get(FactoryFixtureWorkerChannel::class),
            $factory->get(FactoryFixtureChannelKind::Worker),
        );
    }

    /**
     * @return array{SemitexaContainer, ContractFactory, FactoryFixtureScopedChannel}
     */
    private function build(): array
    {
        $container = new SemitexaContainer();
        $prototype = (new \ReflectionClass(FactoryFixtureScopedChannel::class))->newInstanceWithoutConstructor();
        $worker = new FactoryFixtureWorkerChannel();

        $store = (new ReflectionProperty(SemitexaContainer::class, 'instanceStore'))->getValue($container);
        \assert($store instanceof InstanceStore);
        $store->prototypes[FactoryFixtureScopedChannel::class] = $prototype;
        $store->prototypes[FactoryFixtureExecutionState::class] = new FactoryFixtureExecutionState();
        $store->readonly[FactoryFixtureWorkerChannel::class] = $worker;

        $typeMap = (new ReflectionProperty(SemitexaContainer::class, 'typeMap'))->getValue($container);
        \assert($typeMap instanceof TypeMap);
        foreach ([FactoryFixtureScopedChannel::class, FactoryFixtureExecutionState::class] as $class) {
            $typeMap->registeredClasses[$class] = true;
            $typeMap->executionScoped[$class] = true;
        }

        $injectionMap = (new ReflectionProperty(SemitexaContainer::class, 'injectionMap'))->getValue($container);
        \assert($injectionMap instanceof InjectionMap);
        $injectionMap->injections[FactoryFixtureScopedChannel::class] = [
            'state' => ['kind' => 'mutable', 'type' => FactoryFixtureExecutionState::class, 'optional' => false],
        ];

        $factories = [];
        (new GraphBuilder())->buildFactories(
            [FactoryFixtureChannel::class => [
                'active' => FactoryFixtureScopedChannel::class,
                'implementations' => [
                    ['module' => 'A', 'class' => FactoryFixtureScopedChannel::class, 'factoryKey' => FactoryFixtureChannelKind::Scoped],
                    ['module' => 'B', 'class' => FactoryFixtureWorkerChannel::class, 'factoryKey' => FactoryFixtureChannelKind::Worker],
                ],
            ]],
            [],
            $store->readonly,
            $store->prototypes,
            $factories,
            $container->get(...),
        );

        $factory = $factories[FactoryFactoryFixtureChannel::class];
        self::assertInstanceOf(ContractFactory::class, $factory);

        return [$container, $factory, $prototype];
    }
}
