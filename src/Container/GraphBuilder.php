<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Container\Exception\ContainerBuildException;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Exception\ContainerException;
use Semitexa\Core\Contract\InitializesAfterInjectionInterface;
use Semitexa\Core\Registry\RegistryContractResolverGenerator;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Builds readonly and execution-scoped instance graphs from injection metadata.
 * Handles topological ordering, instance creation, and property injection.
 *
 * @internal Used only by ContainerBootstrapper during build.
 * @phpstan-type ContractImplementation array{module: string, class: class-string, factoryKey?: \BackedEnum|null}
 * @phpstan-type ContractDetail array{implementations: list<ContractImplementation>, active: class-string}
 * @phpstan-type InjectionsMap array<class-string, array<string, array{kind: string, type: class-string, optional?: bool, declared?: class-string}>>
 * @phpstan-type IdToClassMap array<string, class-string>
 * @phpstan-type ObjectMap array<string, object>
 * @phpstan-type FactoryMap array<string, object>
 */
final class GraphBuilder
{
    /**
     * Build readonly (worker-scoped) instances in dependency order.
     *
     * @param IdToClassMap $idToClass
     * @param array<class-string, true> $executionScopedClasses
     * @param InjectionsMap $injections
     * @param ObjectMap $readonlyInstances Existing instances (mutated in place)
     * @param callable(string): ?string $resolveToClass
     */
    public function buildReadonlyGraph(
        array $idToClass,
        array $executionScopedClasses,
        array $injections,
        array &$readonlyInstances,
        callable $resolveToClass,
        ?InjectionAnalyzer $injectionAnalyzer = null,
    ): void {
        $readonlyClasses = [];
        foreach ($idToClass as $id => $class) {
            if (isset($executionScopedClasses[$class])) {
                continue;
            }
            if (interface_exists($id)) {
                continue;
            }
            if ($this->isResolverClass($class)) {
                continue;
            }
            $readonlyClasses[$class] = true;
        }
        $order = $this->topologicalOrder(array_keys($readonlyClasses), $injections, $resolveToClass);
        foreach ($order as $class) {
            $instance = $this->createInstance($class, $injections, $readonlyInstances, $idToClass, $executionScopedClasses, $injectionAnalyzer);
            $readonlyInstances[$class] = $instance;
            foreach ($idToClass as $id => $c) {
                if ($c === $class && $id !== $class) {
                    $readonlyInstances[$id] = $instance;
                }
            }
        }
    }

    /**
     * Build execution-scoped prototypes in dependency order.
     *
     * @param array<class-string, true> $executionScopedClasses
     * @param InjectionsMap $injections
     * @param ObjectMap $readonlyInstances
     * @param IdToClassMap $idToClass (mutated: registers prototypes)
     * @param array<class-string, object> $executionScopedPrototypes (mutated in place)
     * @param callable(string): ?string $resolveToClass
     */
    public function buildExecutionScopedPrototypes(
        array $executionScopedClasses,
        array $injections,
        array $readonlyInstances,
        array &$idToClass,
        array &$executionScopedPrototypes,
        callable $resolveToClass,
        ?InjectionAnalyzer $injectionAnalyzer = null,
    ): void {
        $order = $this->topologicalOrder(array_keys($executionScopedClasses), $injections, $resolveToClass);
        foreach ($order as $class) {
            $prototype = $this->createInstance($class, $injections, $readonlyInstances, $idToClass, $executionScopedClasses, $injectionAnalyzer, deferInitialization: true);
            $executionScopedPrototypes[$class] = $prototype;
            $idToClass[$class] = $class;
            foreach ($idToClass as $id => $c) {
                if ($c === $class && $id !== $class) {
                    $idToClass[$id] = $class;
                }
            }
        }
    }

    /**
     * Build resolver instances (generated registry resolvers with constructor injection).
     *
     * @param array<class-string, ContractDetail> $contractDetails
     * @param IdToClassMap $idToClass
     * @param ObjectMap $readonlyInstances (mutated in place)
     * @param ObjectMap $executionScopedPrototypes
     * @param InjectionsMap $injections
     */
    public function buildResolvers(
        array $contractDetails,
        array $idToClass,
        array &$readonlyInstances,
        array $executionScopedPrototypes,
        array $injections,
        ?InjectionAnalyzer $injectionAnalyzer = null,
    ): void {
        foreach ($contractDetails as $interface => $data) {
            $resolverClass = $this->getResolverClassForContract($interface);
            if ($resolverClass === null || !class_exists($resolverClass) || !isset($idToClass[$resolverClass])) {
                continue;
            }
            $resolver = $this->createInstanceWithConstructor($resolverClass, $readonlyInstances, $executionScopedPrototypes, $idToClass, $injections, $injectionAnalyzer);
            $readonlyInstances[$resolverClass] = $resolver;
        }
    }

    /**
     * Build factory instances for contracts with 2+ implementations.
     *
     * @param array<class-string, ContractDetail> $contractDetails
     * @param array<class-string, class-string> $interfaceToResolver
     * @param ObjectMap $readonlyInstances
     * @param ObjectMap $executionScopedPrototypes
     * @param FactoryMap $factories (mutated in place) Factory* interface => the generated
     *        typed factory, or the generic one when that class is absent
     * @param \Closure(class-string): object $resolveService The container's get(): resolves an
     *        execution-scoped implementation per call (clone, mutable injection, initialize()).
     */
    public function buildFactories(
        array $contractDetails,
        array $interfaceToResolver,
        array $readonlyInstances,
        array $executionScopedPrototypes,
        array &$factories,
        \Closure $resolveService,
        array &$genericFactories = [],
    ): void {
        // Never hand out an execution-scoped prototype itself: it would be one
        // object shared by every request, never injected nor initialized.
        $perExecution = static function (object $impl) use ($executionScopedPrototypes, $resolveService): object {
            $class = $impl::class;
            if (!isset($executionScopedPrototypes[$class])) {
                return $impl;
            }

            return static fn (): object => $resolveService($class);
        };

        foreach ($contractDetails as $baseInterface => $data) {
            $implementations = $data['implementations'];
            if (count($implementations) < 2) {
                continue;
            }
            $factoryInterface = RegistryContractResolverGenerator::getFactoryInterfaceForContract($baseInterface);
            if ($factoryInterface === null || !interface_exists($factoryInterface)) {
                continue;
            }
            $active = $data['active'];
            $defaultImpl = null;
            $resolverClass = $interfaceToResolver[$baseInterface] ?? null;
            if ($resolverClass !== null) {
                $resolver = $readonlyInstances[$resolverClass] ?? null;
                if ($resolver !== null && is_callable([$resolver, 'getContract'])) {
                    $contract = $resolver->getContract();
                    if (is_object($contract)) {
                        $defaultImpl = $contract;
                    }
                }
            }
            if ($defaultImpl === null) {
                $defaultImpl = $readonlyInstances[$active] ?? $executionScopedPrototypes[$active] ?? null;
            }
            if ($defaultImpl === null) {
                continue;
            }
            $defaultImpl = $perExecution($defaultImpl);
            $byKey = [];
            $enumKeys = [];
            $enumClass = null;
            foreach ($implementations as $impl) {
                $implClass = $impl['class'];
                $factoryKey = $impl['factoryKey'] ?? null;
                if (!$factoryKey instanceof \BackedEnum) {
                    throw new ContainerBuildException(
                        "Factory contract {$baseInterface} requires enum-backed factoryKey for every implementation. Missing on {$implClass}."
                    );
                }
                $currentEnumClass = $factoryKey::class;
                if ($enumClass === null) {
                    $enumClass = $currentEnumClass;
                } elseif ($enumClass !== $currentEnumClass) {
                    throw new ContainerBuildException(
                        "Factory contract {$baseInterface} mixes enum types {$enumClass} and {$currentEnumClass}."
                    );
                }
                $key = (string) $factoryKey->value;
                $inst = $readonlyInstances[$implClass] ?? $executionScopedPrototypes[$implClass] ?? null;
                if ($inst !== null) {
                    $byKey[$key] = $perExecution($inst);
                    $enumKeys[$key] = $factoryKey;
                }
            }
            foreach ($enumClass::cases() as $case) {
                if (!isset($byKey[(string) $case->value])) {
                    throw new ContainerBuildException(
                        "Factory contract {$baseInterface} is missing implementation for {$enumClass}::{$case->name}."
                    );
                }
            }
            $generic = new ContractFactory($defaultImpl, $byKey, $enumKeys);
            $genericFactories[$factoryInterface] = $generic;
            $factories[$factoryInterface] = self::typedFactory($baseInterface, $factoryInterface, $generic);
        }
    }

    /**
     * An #[InjectAsFactory] property is typed as the Factory* interface, which
     * the generic ContractFactory cannot implement (its get() takes the
     * contract's concrete enum). The generated App\Registry\Contracts\*Factory
     * class implements it by delegating to the generic factory, so hand that
     * out when it exists. Otherwise the generic factory is kept, and injecting
     * it into a property of the interface type fails boot with a pointer to
     * the generator (see injectFactoriesIntoPrototypes()).
     */
    private static function typedFactory(string $baseInterface, string $factoryInterface, ContractFactory $factory): object
    {
        if ($factory instanceof $factoryInterface) {
            return $factory;
        }
        $generated = RegistryContractResolverGenerator::getGeneratedFactoryClassForContract($baseInterface);
        if (!class_exists($generated) || !is_subclass_of($generated, $factoryInterface)) {
            return $factory;
        }
        $params = (new ReflectionClass($generated))->getConstructor()?->getParameters() ?? [];
        $type = isset($params[0]) ? $params[0]->getType() : null;
        if (count($params) !== 1 || !$type instanceof ReflectionNamedType
            || !is_a(ContractFactory::class, $type->getName(), true)) {
            return $factory; // Generated by an older generator: stale shape.
        }

        return new $generated($factory);
    }

    /**
     * Inject factory instances into execution-scoped prototypes that have InjectAsFactory.
     *
     * @param array<class-string, object> $executionScopedPrototypes
     * @param InjectionsMap $injections
     * @param ObjectMap $factories
     * @param array<string, ContractFactory> $genericFactories
     */
    public function injectFactoriesIntoPrototypes(
        array $executionScopedPrototypes,
        array $injections,
        array $factories,
        array $genericFactories = [],
    ): void {
        foreach ($executionScopedPrototypes as $class => $instance) {
            $classInjections = $injections[$class] ?? [];
            foreach ($classInjections as $propName => $info) {
                if ($info['kind'] !== 'factory') {
                    continue;
                }
                $factory = self::factoryFor($class, $propName, $info, $factories, $genericFactories);
                if ($factory === null) {
                    continue;
                }
                $prop = (new ReflectionClass($instance))->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue($instance, $factory);
            }
        }
    }

    /**
     * The factory an #[InjectAsFactory] property receives, chosen by its declared
     * type: the generic ContractFactory when that type accepts it (ContractFactory,
     * ContractFactoryInterface, or a Factory* interface it satisfies), otherwise
     * the generated typed factory. Null when the contract has no factory.
     *
     * @param array{type: string, declared?: string} $info
     * @param array<string, object> $factories
     * @param array<string, ContractFactory> $genericFactories
     */
    public static function factoryFor(string $class, string $propName, array $info, array $factories, array $genericFactories): ?object
    {
        $key = $info['type'];
        $declared = $info['declared'] ?? $key;
        $generic = $genericFactories[$key] ?? null;
        if ($generic instanceof $declared) {
            return $generic;
        }
        $factory = $factories[$key] ?? null;
        if ($factory === null || $factory instanceof $declared) {
            return $factory;
        }

        throw new ContainerBuildException(self::missingTypedFactoryMessage($class, $propName, $key));
    }

    private static function missingTypedFactoryMessage(string $class, string $propName, string $factoryInterface): string
    {
        $pos = strrpos($factoryInterface, '\\');
        $namespace = $pos === false ? '' : substr($factoryInterface, 0, $pos + 1);
        $short = $pos === false ? $factoryInterface : substr($factoryInterface, $pos + 1);
        $baseInterface = $namespace . (string) preg_replace('/^Factory/', '', $short);
        $generated = interface_exists($baseInterface)
            ? RegistryContractResolverGenerator::getGeneratedFactoryClassForContract($baseInterface)
            : 'App\\Registry\\Contracts\\*Factory';

        return "Cannot inject #[InjectAsFactory] {$class}::\${$propName} (type: {$factoryInterface}): "
            . "the generated factory class {$generated} implementing it is missing or out of date. "
            . 'Run `bin/semitexa registry:sync:contracts` to generate it.';
    }

    /**
     * @param list<class-string> $classes
     * @param InjectionsMap $injections
     * @param callable(string): ?string $resolveToClass
     * @return list<class-string>
     */
    public function topologicalOrder(array $classes, array $injections, callable $resolveToClass): array
    {
        $dep = [];
        foreach ($classes as $c) {
            $dep[$c] = [];
            $classInjections = $injections[$c] ?? [];
            foreach ($classInjections as $info) {
                if ($info['kind'] === 'factory') {
                    continue;
                }
                $target = $resolveToClass($info['type']);
                if ($target !== null && in_array($target, $classes, true)) {
                    $dep[$c][] = $target;
                }
            }
        }
        $out = [];
        $visited = [];
        $visit = function (string $c) use (&$visit, $dep, $classes, &$out, &$visited) {
            if (isset($visited[$c])) {
                return;
            }
            $visited[$c] = true;
            foreach ($dep[$c] ?? [] as $d) {
                if (in_array($d, $classes, true)) {
                    $visit($d);
                }
            }
            $out[] = $c;
        };
        foreach ($classes as $c) {
            $visit($c);
        }
        /** @var list<class-string> $out */
        return $out;
    }

    /**
     * Create instance of a container-managed framework object.
     *
     * The container instantiates via newInstanceWithoutConstructor() by design:
     * dependencies flow through property attributes (#[InjectAsReadonly],
     * #[InjectAsMutable], #[InjectAsFactory], #[Config]), not through constructor
     * arguments. Declaring __construct with parameters on a container-managed
     * class is therefore treated as an attempt to use the constructor as a DI
     * channel and rejected.
     *
     * This does not ban constructors outright. A parameterless __construct on a
     * container-managed class is never called — so an EMPTY one is harmless and
     * allowed, while one with a body is a silent no-op and is rejected by
     * `lint:di` and the phpstan rule. Initialization belongs in
     * {@see InitializesAfterInjectionInterface::initialize()}, which this class
     * calls once injection is complete. Constructors are unrestricted on value
     * objects, DTOs, payloads, resources, and any class not managed here.
     *
     * @param class-string $class
     * @param InjectionsMap $injections
     * @param ObjectMap $readonlyInstances
     * @param IdToClassMap $idToClass
     * @param array<class-string, true> $executionScopedClasses
     */
    private function createInstance(
        string $class,
        array $injections,
        array $readonlyInstances,
        array $idToClass,
        array $executionScopedClasses,
        ?InjectionAnalyzer $injectionAnalyzer = null,
        bool $deferInitialization = false,
    ): object {
        $ref = new ReflectionClass($class);

        $ctor = $ref->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfParameters() > 0) {
            throw new InjectionException(
                targetClass: $class,
                propertyName: '__construct',
                propertyType: 'constructor',
                injectionKind: 'constructor',
                message: "Constructor injection is not the DI channel for container-managed {$class}. "
                    . "Declare dependencies as protected properties with #[InjectAsReadonly], "
                    . "#[InjectAsMutable], #[InjectAsFactory], or #[Config] instead of constructor "
                    . "parameters. Constructors are not banned — a parameterless __construct is "
                    . "still allowed here, and constructors are unrestricted on non-container-managed "
                    . "types (DTOs, payloads, resources, value objects).",
            );
        }

        try {
            $instance = $ref->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            throw new ContainerException("Container: cannot instantiate {$class}: " . $e->getMessage(), $e);
        }

        if ($injectionAnalyzer !== null) {
            $injectionAnalyzer->injectConfigProperties($instance, $class, $ref);
        }
        $this->injectPropertiesInto($instance, $class, $injections, $readonlyInstances, $idToClass, $executionScopedClasses);
        // Deferred for an execution-scoped PROTOTYPE: its #[InjectAsMutable] and
        // factory properties are populated per execution, on the clone, by
        // SemitexaContainer — so initializing here would run against
        // uninitialized typed properties at boot and never run at all for the
        // clone anyone actually receives. The container calls
        // {@see initializeInstance()} there instead.
        if (!$deferInitialization) {
            $this->initializeAfterInjection($instance, $class);
        }

        return $instance;
    }

    /**
     * Resolve constructor params from container and create instance; then set InjectAs* properties.
     * Used for resolver classes (generated registry resolvers) that have constructor dependencies.
     *
     * @param class-string $class
     * @param ObjectMap $readonlyInstances
     * @param ObjectMap $executionScopedPrototypes
     * @param IdToClassMap $idToClass
     * @param InjectionsMap $injections
     */
    private function createInstanceWithConstructor(
        string $class,
        array $readonlyInstances,
        array $executionScopedPrototypes,
        array $idToClass,
        array $injections,
        ?InjectionAnalyzer $injectionAnalyzer = null,
    ): object {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $args = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    $typeLabel = $type !== null ? (string) $type : 'untyped';
                    throw new ContainerException(sprintf(
                        'Container cannot autowire %s::__construct($%s): a parameter of type "%s" is not a service — the container only autowires class-typed constructor parameters. '
                        . 'Fix: give $%s a default value, or inject it as an #[InjectAsReadonly] property instead, or change its type to a registered #[AsService] class.',
                        $class,
                        $param->getName(),
                        $typeLabel,
                        $param->getName(),
                    ));
                }
                $name = $type->getName();
                $mappedClass = $idToClass[$name] ?? null;
                $inst = $readonlyInstances[$name]
                    ?? ($mappedClass !== null ? ($readonlyInstances[$mappedClass] ?? null) : null)
                    ?? $executionScopedPrototypes[$name]
                    ?? ($mappedClass !== null ? ($executionScopedPrototypes[$mappedClass] ?? null) : null);
                if ($inst === null) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    throw new ContainerException(sprintf(
                        'Container cannot build %s: its constructor dependency %s ($%s) is not a registered service. '
                        . 'Fix: mark %s with #[AsService] (and ensure its module is active or it lives under the project src/), or provide it via a factory.',
                        $class,
                        $name,
                        $param->getName(),
                        $name,
                    ));
                }
                $args[] = $inst;
            }
        }
        $instance = $args !== [] ? $ref->newInstanceArgs($args) : $ref->newInstance();
        if ($injectionAnalyzer !== null) {
            $injectionAnalyzer->injectConfigProperties($instance, $class, $ref);
        }
        // Derive the scoped-class map from the prototypes so the
        // ExecutionScoped-trap diagnostic works on this path too.
        $this->injectPropertiesInto(
            $instance,
            $class,
            $injections,
            $readonlyInstances,
            $idToClass,
            array_fill_keys(array_keys($executionScopedPrototypes), true),
        );
        $this->initializeAfterInjection($instance, $class);

        return $instance;
    }

    /**
     * Run the one hook a container-managed class has for initialization.
     *
     * The constructor is not it: these objects are built with
     * newInstanceWithoutConstructor(), so anything written in one never runs. A
     * class that needs to do work after its dependencies arrive implements
     * {@see InitializesAfterInjectionInterface} and gets called here — after
     * every property is populated, before anyone holds the object.
     *
     * A throw is not swallowed. Half-initialized is the state this whole design
     * exists to make unreachable, so the failure names the class and stops.
     *
     * @param class-string $class
     */
    /**
     * Run initialize() on an execution-scoped clone, once the container has
     * finished populating its per-execution properties.
     *
     * Public because the completion of injection for these classes happens in
     * {@see SemitexaContainer}, not here: the prototype built at boot is only
     * half of the object, and the half that varies per execution is attached
     * on the clone.
     *
     * @param class-string $class
     */
    public function initializeInstance(object $instance, string $class): void
    {
        $this->initializeAfterInjection($instance, $class);
    }

    private function initializeAfterInjection(object $instance, string $class): void
    {
        if (!$instance instanceof InitializesAfterInjectionInterface) {
            return;
        }

        try {
            $instance->initialize();
        } catch (\Throwable $e) {
            throw new ContainerException(
                "Container: {$class}::initialize() failed after injection: " . $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * Inject #[InjectAs*] properties with strict failure.
     * Every annotated property must resolve, unless it is declared optional.
     *
     * @param class-string $class
     * @param InjectionsMap $injections
     * @param ObjectMap $readonlyInstances
     * @param IdToClassMap $idToClass
     * @param array<class-string, true> $executionScopedClasses
     */
    private function injectPropertiesInto(
        object $instance,
        string $class,
        array $injections,
        array $readonlyInstances,
        array $idToClass,
        array $executionScopedClasses,
    ): void {
        $ref = new ReflectionClass($instance);
        $classInjections = $injections[$class] ?? [];

        foreach ($classInjections as $propName => $info) {
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $kind = $info['kind'];
            $typeName = $info['type'];

            // Factories do not exist yet: FactoryBuildPhase builds and injects
            // them after the graph; ValidationPhase reports a missing one.
            if ($kind === 'factory') {
                continue;
            }

            $resolved = $this->resolveForBuildInjection($kind, $typeName, $readonlyInstances, $idToClass);

            if ($resolved !== null) {
                $prop->setValue($instance, $resolved);
                continue;
            }

            // For mutable properties that are execution-context types, skip during boot
            if ($kind === 'mutable' && $this->isExecutionContextType($typeName)) {
                continue;
            }

            // For mutable properties in execution-scoped classes, skip during boot
            if ($kind === 'mutable' && isset($executionScopedClasses[$class])) {
                continue;
            }

            // Why it resolved to nothing decides the case. An optional dependency
            // with NO implementation stays uninitialized, as the attribute
            // promises. One whose implementation exists but is #[ExecutionScoped]
            // is the trap below, and `optional` must not turn that into a boot
            // that succeeds and an injection that never happens.
            $trap = self::describeExecutionScopedTrap($typeName, $idToClass, $executionScopedClasses);
            if (!empty($info['optional']) && $trap === '') {
                continue;
            }

            throw new InjectionException(
                targetClass: $class,
                propertyName: $propName,
                propertyType: $typeName,
                injectionKind: $kind,
                message: "Cannot inject {$class}::\${$propName} (type: {$typeName}, "
                    . "kind: {$kind}). No binding found."
                    . $trap,
            );
        }
    }

    /**
     * The recurring footgun behind "No binding found": the requested type IS
     * implemented, but the implementation is `#[ExecutionScoped]`, and
     * execution-scoped services cannot be handed out as boot-time property
     * injections (one frozen instance would smuggle per-request state across
     * requests). Without this note the failure reads as a missing service and
     * has historically been "fixed" by binding a worker-local in-memory
     * substitute — which silently breaks cross-worker behavior (the collab
     * draft-store regression), or by trial-and-error against a boot
     * crash-loop (the calendar repository). Name the trap and the two
     * sanctioned ways out at the exact point of failure.
     *
     * @param IdToClassMap $idToClass
     * @param array<class-string, true> $executionScopedClasses
     */
    public static function describeExecutionScopedTrap(string $typeName, array $idToClass, array $executionScopedClasses): string
    {
        $implementer = null;
        $mapped = $idToClass[$typeName] ?? null;
        if ($mapped !== null && isset($executionScopedClasses[$mapped])) {
            $implementer = $mapped;
        } else {
            foreach (array_keys($executionScopedClasses) as $scopedClass) {
                if (is_a($scopedClass, $typeName, true)) {
                    $implementer = $scopedClass;
                    break;
                }
            }
        }

        if ($implementer === null) {
            return '';
        }

        return " NOTE: {$implementer} implements this type but is #[ExecutionScoped] — "
            . 'execution-scoped services cannot be injected as boot-time properties into '
            . 'container-built classes (one frozen instance would leak per-request state '
            . 'across requests). Sanctioned ways out: (1) make the implementation a plain '
            . 'singleton that resolves per-request state AT CALL TIME through a '
            . 'coroutine-local seam (inject TenantContextStoreInterface and read tryGet() '
            . 'per call — see CalendarEventDbRepository / FormCollabDraftDbRepository), or '
            . "(2) drop #[ExecutionScoped] from {$implementer} if it holds no per-execution "
            . 'state. Do NOT bind a worker-local in-memory substitute: it boots, then '
            . 'silently breaks cross-worker behavior (the collab draft-store regression).';
    }

    /**
     * Resolve a dependency during build phase (no execution context yet, no factories yet).
     *
     * @param ObjectMap $readonlyInstances
     * @param IdToClassMap $idToClass
     */
    private function resolveForBuildInjection(string $kind, string $typeName, array $readonlyInstances, array $idToClass): ?object
    {
        return match ($kind) {
            'factory' => null, // Factories are injected after build
            'readonly' => $readonlyInstances[$typeName]
                ?? $readonlyInstances[$idToClass[$typeName] ?? '']
                ?? null,
            'mutable' => null, // Mutable resolved at execution time
            default => throw new \InvalidArgumentException("Unknown injection kind: {$kind}"),
        };
    }

    private function isExecutionContextType(string $typeName): bool
    {
        return in_array($typeName, SemitexaContainer::EXECUTION_CONTEXT_TYPES, true);
    }

    /**
     * @param class-string $class
     */
    public function isResolverClass(string $class): bool
    {
        return str_contains($class, '\\Registry\\Contracts\\') && str_ends_with($class, 'Resolver');
    }

    public function getResolverClassForContract(string $interface): ?string
    {
        if (!interface_exists($interface)) {
            return null;
        }
        $short = (new ReflectionClass($interface))->getShortName();
        $resolverShort = preg_replace('/Interface$/', 'Resolver', $short);
        if ($resolverShort === $short) {
            $resolverShort = $short . 'Resolver';
        }
        return 'App\\Registry\\Contracts\\' . $resolverShort;
    }
}
