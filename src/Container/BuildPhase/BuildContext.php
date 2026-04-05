<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\InjectionAnalyzer;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;

/**
 * Mutable accumulator carrying state between build phases.
 *
 * Each phase reads from fields set by earlier phases and writes its own results.
 * After all phases complete, the typed stores (InstanceStore, TypeMap, InjectionMap)
 * contain the final container state.
 *
 * Never shared across threads/coroutines — exists only during the synchronous build sequence.
 *
 * @internal Used only by ContainerBootstrapper and BuildPhaseInterface implementations.
 * @phpstan-type ContractImplementation array{module: string, class: class-string, factoryKey?: \BackedEnum|null}
 * @phpstan-type ContractDetail array{implementations: list<ContractImplementation>, active: class-string}
 */
final class BuildContext
{
    // --- Typed stores (final destination for all build data) ---

    public readonly InstanceStore $instanceStore;
    public readonly TypeMap $typeMap;
    public readonly InjectionMap $injectionMap;

    // --- Discovery instances (set by early phases) ---

    public ?ClassDiscovery $classDiscovery = null;
    public ?ModuleRegistry $moduleRegistry = null;
    public ?RouteRegistry $routeRegistry = null;
    public ?AttributeDiscovery $attributeDiscovery = null;
    public ?HandlerRegistry $handlerRegistry = null;
    public ?PayloadPartRegistry $payloadPartRegistry = null;
    public ?EventListenerRegistry $eventListenerRegistry = null;
    public ?PipelineListenerRegistry $pipelineListenerRegistry = null;
    public ?InjectionAnalyzer $injectionAnalyzer = null;

    // --- Build-time arrays (used during build, transferred to TypeMap at end) ---

    /** @var array<string, class-string> id => concrete class */
    public array $idToClass = [];

    /** @var array<class-string, true> */
    public array $executionScopedClasses = [];

    /** @var array<class-string, class-string> interface => resolver class */
    public array $interfaceToResolver = [];

    /** @var array<class-string, array<string, array{kind: string, type: class-string}>> */
    public array $injections = [];

    /** @var array<class-string, ContractDetail> */
    public array $contractDetails = [];

    public function __construct(
        InstanceStore $instanceStore,
        TypeMap $typeMap,
        InjectionMap $injectionMap,
    ) {
        $this->instanceStore = $instanceStore;
        $this->typeMap = $typeMap;
        $this->injectionMap = $injectionMap;
    }

    /**
     * Resolve an id to a concrete class using the build-time idToClass map.
     *
     * @return class-string|null
     */
    public function resolveToClass(string $id): ?string
    {
        if (isset($this->idToClass[$id])) {
            return $this->idToClass[$id];
        }
        if (class_exists($id) && !interface_exists($id)) {
            /** @var class-string $id */
            return $id;
        }
        return null;
    }

    /**
     * Transfer build-time arrays to typed stores.
     * Called once after all phases complete.
     */
    public function transferToStores(): void
    {
        $this->typeMap->populateFromBuildArrays(
            $this->idToClass,
            $this->executionScopedClasses,
            $this->interfaceToResolver,
        );
        $this->injectionMap->injections = $this->injections;
    }
}
