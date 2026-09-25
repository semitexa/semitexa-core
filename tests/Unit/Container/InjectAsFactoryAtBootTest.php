<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\BuildPhase\BuildContext;
use Semitexa\Core\Container\BuildPhase\FactoryBuildPhase;
use Semitexa\Core\Container\BuildPhase\ValidationPhase;
use Semitexa\Core\Container\ContractFactory;
use Semitexa\Core\Container\GraphBuilder;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Contract\ContractFactoryInterface;

interface BootFactoryChannel
{
}

interface FactoryBootFactoryChannel extends ContractFactoryInterface
{
}

enum BootFactoryChannelKind: string
{
    case Mail = 'mail';
    case Sms = 'sms';
}

final class BootFactoryMailChannel implements BootFactoryChannel
{
}

final class BootFactorySmsChannel implements BootFactoryChannel
{
}

final class BootFactoryConsumer
{
    // Typed as the base interface: the container hands out a ContractFactory,
    // which does not implement the Factory* interface itself.
    protected ContractFactoryInterface $channels;

    public function channels(): ContractFactoryInterface
    {
        return $this->channels;
    }
}

/**
 * #[InjectAsFactory] resolves to nothing while the graph is built (factories
 * are built afterwards, in FactoryBuildPhase), so the build pass fell through
 * to "No binding found" and failed boot for any container-built consumer.
 */
final class InjectAsFactoryAtBootTest extends TestCase
{
    #[Test]
    public function a_worker_scoped_consumer_with_an_injected_factory_boots_and_receives_the_factory(): void
    {
        $context = $this->context();
        $readonly = [
            BootFactoryMailChannel::class => new BootFactoryMailChannel(),
            BootFactorySmsChannel::class => new BootFactorySmsChannel(),
        ];
        (new GraphBuilder())->buildReadonlyGraph(
            [BootFactoryConsumer::class => BootFactoryConsumer::class],
            [],
            $context->injections,
            $readonly,
            static fn (string $id): ?string => class_exists($id) ? $id : null,
        );
        foreach ($readonly as $id => $instance) {
            $context->instanceStore->readonly[$id] = $instance;
        }

        (new FactoryBuildPhase())->execute($context);
        (new ValidationPhase())->execute($context);

        $consumer = $context->instanceStore->readonly[BootFactoryConsumer::class];
        self::assertInstanceOf(BootFactoryConsumer::class, $consumer);
        self::assertInstanceOf(ContractFactory::class, $consumer->channels());
    }

    #[Test]
    public function an_execution_scoped_consumer_with_an_injected_factory_boots_and_receives_the_factory(): void
    {
        $context = $this->context();
        $context->instanceStore->readonly[BootFactoryMailChannel::class] = new BootFactoryMailChannel();
        $context->instanceStore->readonly[BootFactorySmsChannel::class] = new BootFactorySmsChannel();
        $idToClass = [];
        $prototypes = [];
        (new GraphBuilder())->buildExecutionScopedPrototypes(
            [BootFactoryConsumer::class => true],
            $context->injections,
            $context->instanceStore->readonly,
            $idToClass,
            $prototypes,
            static fn (string $id): ?string => class_exists($id) ? $id : null,
        );
        $context->instanceStore->prototypes[BootFactoryConsumer::class] = $prototypes[BootFactoryConsumer::class];
        $context->executionScopedClasses[BootFactoryConsumer::class] = true;

        (new FactoryBuildPhase())->execute($context);
        (new ValidationPhase())->execute($context);

        $consumer = $context->instanceStore->prototypes[BootFactoryConsumer::class];
        self::assertInstanceOf(BootFactoryConsumer::class, $consumer);
        self::assertInstanceOf(ContractFactory::class, $consumer->channels());
    }

    private function context(): BuildContext
    {
        $context = new BuildContext(new InstanceStore(), new TypeMap(), new InjectionMap());
        $context->injections = [
            BootFactoryConsumer::class => [
                'channels' => ['kind' => 'factory', 'type' => FactoryBootFactoryChannel::class, 'optional' => false],
            ],
        ];
        $context->contractDetails = [BootFactoryChannel::class => [
            'active' => BootFactoryMailChannel::class,
            'implementations' => [
                ['module' => 'A', 'class' => BootFactoryMailChannel::class, 'factoryKey' => BootFactoryChannelKind::Mail],
                ['module' => 'B', 'class' => BootFactorySmsChannel::class, 'factoryKey' => BootFactoryChannelKind::Sms],
            ],
        ]];
        $store = $context->instanceStore;
        $context->resolveService = static fn (string $id): object => $store->readonly[$id];

        return $context;
    }
}
