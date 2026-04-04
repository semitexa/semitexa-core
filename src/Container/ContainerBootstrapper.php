<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\HandleRequest;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;

/**
 * Orchestrates the container build process through all boot phases.
 *
 * @internal Used only by SemitexaContainer::build().
 */
final class ContainerBootstrapper
{
    public function __construct(
        private readonly InjectionAnalyzer $injectionAnalyzer,
        private readonly GraphBuilder $graphBuilder,
        private readonly CycleDetector $cycleDetector,
    ) {}

    /**
     * Run the full container build sequence.
     *
     * @param array<string, object> $readonlyInstances
     * @param array<string, object> $executionScopedPrototypes
     * @param array<string, object> $factories
     * @param array<string, string> $idToClass
     * @param array<string, true> $executionScopedClasses
     * @param array<string, string> $interfaceToResolver
     * @param array<string, array<string, array{kind: string, type: string}>> $injections
     */
    public function build(
        array &$readonlyInstances,
        array &$executionScopedPrototypes,
        array &$factories,
        array &$idToClass,
        array &$executionScopedClasses,
        array &$interfaceToResolver,
        array &$injections,
    ): void {
        BootDiagnostics::begin();

        // === BootPhase::ClassmapLoad ===
        $classDiscovery = new ClassDiscovery();
        $classDiscovery->initialize();

        // === BootPhase::ModuleDiscovery ===
        $moduleRegistry = new ModuleRegistry();
        $moduleRegistry->initialize();

        // === BootPhase::AttributeScan ===
        $routeRegistry = new RouteRegistry();
        $attributeDiscovery = new AttributeDiscovery($classDiscovery, $moduleRegistry, $routeRegistry);
        $attributeDiscovery->initialize();

        // === BootPhase::RegistryBuild ===
        $eventListenerRegistry = new EventListenerRegistry($classDiscovery, $moduleRegistry);
        $eventListenerRegistry->ensureBuilt();

        $pipelineListenerRegistry = new PipelineListenerRegistry($classDiscovery, $moduleRegistry);
        $pipelineListenerRegistry->ensureBuilt();

        // Register discovery instances as readonly services
        $readonlyInstances[ClassDiscovery::class] = $classDiscovery;
        $readonlyInstances[ModuleRegistry::class] = $moduleRegistry;
        $readonlyInstances[RouteRegistry::class] = $routeRegistry;
        $readonlyInstances[AttributeDiscovery::class] = $attributeDiscovery;
        $readonlyInstances[EventListenerRegistry::class] = $eventListenerRegistry;
        $readonlyInstances[PipelineListenerRegistry::class] = $pipelineListenerRegistry;

        // === BootPhase::ContractResolution ===
        $registry = new ServiceContractRegistry($classDiscovery, $moduleRegistry);
        $contractDetails = $registry->getContractDetails();
        foreach ($contractDetails as $interface => $data) {
            $active = $data['active'] ?? null;
            foreach ($data['implementations'] ?? [] as $impl) {
                $implClass = $impl['class'];
                $idToClass[$implClass] = $implClass;
            }
            if ($active !== null) {
                $idToClass[$interface] = $active;
                $resolverClass = $this->graphBuilder->getResolverClassForContract($interface);
                if ($resolverClass !== null && class_exists($resolverClass)) {
                    $idToClass[$resolverClass] = $resolverClass;
                    $interfaceToResolver[$interface] = $resolverClass;
                }
            }
        }

        // === BootPhase::ServiceRegistration ===
        foreach ($attributeDiscovery->getDiscoveredPayloadHandlerClassNames() as $handlerClass) {
            $idToClass[$handlerClass] = $handlerClass;
        }
        foreach ($classDiscovery->findClassesWithAttribute(AsService::class) as $serviceClass) {
            $idToClass[$serviceClass] = $serviceClass;
        }
        if (class_exists(\Semitexa\Orm\Discovery\RepositoryDiscovery::class)) {
            $repositoryDiscovery = new \Semitexa\Orm\Discovery\RepositoryDiscovery($classDiscovery);
            foreach ($repositoryDiscovery->discoverRepositoryClasses() as $repositoryClass) {
                $idToClass[$repositoryClass] = $repositoryClass;
            }
        }
        // Auth handlers need execution-scoped injection
        if (class_exists(\Semitexa\Auth\Attribute\AsAuthHandler::class)) {
            foreach ($classDiscovery->findClassesWithAttribute(\Semitexa\Auth\Attribute\AsAuthHandler::class) as $handlerClass) {
                $idToClass[$handlerClass] = $handlerClass;
            }
        }
        // Pipeline listeners are resolved per execution
        foreach ([AuthCheck::class, HandleRequest::class] as $phaseClass) {
            foreach ($pipelineListenerRegistry->getListeners($phaseClass) as $meta) {
                $idToClass[$meta['class']] = $meta['class'];
            }
        }

        // === BootPhase::ScopeDetection ===
        $this->injectionAnalyzer->setDiscoveryInstances($classDiscovery, $attributeDiscovery);
        $executionScopedClasses = $this->injectionAnalyzer->collectExecutionScopedClasses($idToClass);

        // === BootPhase::InjectionAnalysis ===
        $injections = $this->injectionAnalyzer->collectInjections($idToClass, $executionScopedClasses);

        // === BootPhase::CycleDetection ===
        $resolveToClass = fn(string $id): ?string => $this->resolveToClass($id, $idToClass);
        $this->cycleDetector->assertNoCycles($idToClass, $executionScopedClasses, $injections, $resolveToClass);

        // === BootPhase::ReadonlyBuild ===
        $this->graphBuilder->buildReadonlyGraph($idToClass, $executionScopedClasses, $injections, $readonlyInstances, $resolveToClass);

        // === BootPhase::ExecutionScopedBuild ===
        $this->graphBuilder->buildExecutionScopedPrototypes($executionScopedClasses, $injections, $readonlyInstances, $idToClass, $executionScopedPrototypes, $resolveToClass);

        // === BootPhase::ResolverBuild ===
        $this->graphBuilder->buildResolvers($contractDetails, $idToClass, $readonlyInstances, $executionScopedPrototypes, $interfaceToResolver, $injections);

        // === BootPhase::FactoryBuild ===
        $this->graphBuilder->buildFactories($contractDetails, $interfaceToResolver, $readonlyInstances, $executionScopedPrototypes, $factories);

        // Inject factory instances into execution-scoped prototypes that have InjectAsFactory
        $this->graphBuilder->injectFactoriesIntoPrototypes($executionScopedPrototypes, $injections, $factories);

        // === BootPhase::Validation ===
        $this->validateAllBindings($injections, $idToClass, $executionScopedClasses, $readonlyInstances, $executionScopedPrototypes, $factories);

        $strictMode = filter_var(getenv('BOOT_STRICT_MODE'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        BootDiagnostics::current()->finalize(strict: $strictMode);
    }

    private function resolveToClass(string $id, array $idToClass): ?string
    {
        if (isset($idToClass[$id])) {
            return $idToClass[$id];
        }
        if (class_exists($id) && !interface_exists($id)) {
            return $id;
        }
        return null;
    }

    /**
     * Validate all bindings at boot time.
     */
    private function validateAllBindings(
        array $injections,
        array $idToClass,
        array $executionScopedClasses,
        array $readonlyInstances,
        array $executionScopedPrototypes,
        array $factories,
    ): void {
        foreach ($injections as $class => $properties) {
            foreach ($properties as $propName => $info) {
                $kind = $info['kind'];
                $typeName = $info['type'];

                $resolved = match ($kind) {
                    'factory' => $factories[$typeName] ?? null,
                    'readonly' => $readonlyInstances[$typeName]
                        ?? $readonlyInstances[$idToClass[$typeName] ?? '']
                        ?? null,
                    'mutable' => $executionScopedPrototypes[$typeName]
                        ?? $executionScopedPrototypes[$idToClass[$typeName] ?? '']
                        ?? null,
                    default => null,
                };

                if ($resolved !== null) {
                    continue;
                }

                if ($kind === 'mutable' && in_array($typeName, SemitexaContainer::EXECUTION_CONTEXT_TYPES, true)) {
                    continue;
                }

                if ($kind === 'mutable' && isset($executionScopedClasses[$class])) {
                    $protoClass = $idToClass[$typeName] ?? $typeName;
                    if (isset($executionScopedPrototypes[$protoClass]) || isset($executionScopedPrototypes[$typeName])) {
                        continue;
                    }
                }

                throw new Exception\InjectionException(
                    targetClass: $class,
                    propertyName: $propName,
                    propertyType: $typeName,
                    injectionKind: $kind,
                    message: "Boot validation failed: {$class}::\${$propName} "
                        . "(type: {$typeName}, kind: {$kind}) has no binding.",
                );
            }
        }
    }
}
