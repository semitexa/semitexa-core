<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\Lifecycle\LifecycleComponentRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleRegistry;

/**
 * Builds event and pipeline listener registries, and registers all discovery
 * instances as readonly services in the instance store.
 *
 * Preconditions: context->classDiscovery, moduleRegistry, routeRegistry, attributeDiscovery must be set.
 * Postconditions: context->eventListenerRegistry, pipelineListenerRegistry set;
 *                 all discovery instances registered in instanceStore->readonly.
 */
final class RegistryBuildPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->classDiscovery !== null, 'ClassmapLoadPhase must run before RegistryBuildPhase');
        assert($context->moduleRegistry !== null, 'ModuleDiscoveryPhase must run before RegistryBuildPhase');
        assert($context->attributeDiscovery !== null, 'AttributeScanPhase must run before RegistryBuildPhase');
        assert($context->routeRegistry !== null, 'AttributeScanPhase must run before RegistryBuildPhase');
        assert($context->handlerRegistry !== null, 'AttributeScanPhase must run before RegistryBuildPhase');
        assert($context->payloadPartRegistry !== null, 'AttributeScanPhase must run before RegistryBuildPhase');

        $eventListenerRegistry = new EventListenerRegistry($context->classDiscovery, $context->moduleRegistry);
        $eventListenerRegistry->ensureBuilt();

        $pipelineListenerRegistry = new PipelineListenerRegistry($context->classDiscovery, $context->moduleRegistry);
        $pipelineListenerRegistry->ensureBuilt();

        $context->eventListenerRegistry = $eventListenerRegistry;
        $context->pipelineListenerRegistry = $pipelineListenerRegistry;

        // Register discovery instances as readonly services
        $context->instanceStore->readonly[ClassDiscovery::class] = $context->classDiscovery;
        $context->instanceStore->readonly[ModuleRegistry::class] = $context->moduleRegistry;
        $context->instanceStore->readonly[RouteRegistry::class] = $context->routeRegistry;
        $context->instanceStore->readonly[AttributeDiscovery::class] = $context->attributeDiscovery;
        $context->instanceStore->readonly[HandlerRegistry::class] = $context->handlerRegistry;
        $context->instanceStore->readonly[PayloadPartRegistry::class] = $context->payloadPartRegistry;
        $context->instanceStore->readonly[EventListenerRegistry::class] = $eventListenerRegistry;
        $context->instanceStore->readonly[PipelineListenerRegistry::class] = $pipelineListenerRegistry;
        $context->instanceStore->readonly[LifecycleComponentRegistry::class] = new LifecycleComponentRegistry($context->moduleRegistry);
        $context->instanceStore->readonly[ServerLifecycleRegistry::class] = new ServerLifecycleRegistry($context->classDiscovery);
    }

    public function name(): string
    {
        return 'RegistryBuild';
    }
}
