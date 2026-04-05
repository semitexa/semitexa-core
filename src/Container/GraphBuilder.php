<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Container\Exception\ContainerBuildException;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Exception\ContainerException;
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
 * @phpstan-type InjectionsMap array<class-string, array<string, array{kind: string, type: class-string}>>
 * @phpstan-type IdToClassMap array<string, class-string>
 * @phpstan-type ObjectMap array<string, object>
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
     * @param ObjectMap $executionScopedPrototypes (mutated in place)
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
            $prototype = $this->createInstance($class, $injections, $readonlyInstances, $idToClass, $executionScopedClasses, $injectionAnalyzer);
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
     * @param ObjectMap $factories (mutated in place)
     */
    public function buildFactories(
        array $contractDetails,
        array $interfaceToResolver,
        array $readonlyInstances,
        array $executionScopedPrototypes,
        array &$factories,
    ): void {
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
                    $byKey[$key] = $inst;
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
            $factories[$factoryInterface] = new ContractFactory($defaultImpl, $byKey, $enumKeys);
        }
    }

    /**
     * Inject factory instances into execution-scoped prototypes that have InjectAsFactory.
     *
     * @param ObjectMap $executionScopedPrototypes
     * @param InjectionsMap $injections
     * @param ObjectMap $factories
     */
    public function injectFactoriesIntoPrototypes(
        array $executionScopedPrototypes,
        array $injections,
        array $factories,
    ): void {
        foreach ($executionScopedPrototypes as $class => $instance) {
            $classInjections = $injections[$class] ?? [];
            foreach ($classInjections as $propName => $info) {
                if ($info['kind'] !== 'factory') {
                    continue;
                }
                $factory = $factories[$info['type']] ?? null;
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
     * Uses newInstanceWithoutConstructor(). Constructors with parameters are forbidden.
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
    ): object {
        $ref = new ReflectionClass($class);

        $ctor = $ref->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfParameters() > 0) {
            throw new InjectionException(
                targetClass: $class,
                propertyName: '__construct',
                propertyType: 'constructor',
                injectionKind: 'constructor',
                message: "Container-managed framework object {$class} must not have constructor parameters. "
                    . "Use #[InjectAsReadonly], #[InjectAsMutable], #[InjectAsFactory], or #[Config] properties instead.",
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
                    throw new ContainerException("Container: cannot resolve constructor param \${$param->getName()} for {$class}");
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
                    throw new ContainerException("Container: missing dependency for {$class}::__construct(\${$param->getName()}: {$name})");
                }
                $args[] = $inst;
            }
        }
        $instance = $args !== [] ? $ref->newInstanceArgs($args) : $ref->newInstance();
        if ($injectionAnalyzer !== null) {
            $injectionAnalyzer->injectConfigProperties($instance, $class, $ref);
        }
        $this->injectPropertiesInto($instance, $class, $injections, $readonlyInstances, $idToClass, []);
        return $instance;
    }

    /**
     * Inject #[InjectAs*] properties with strict failure.
     * Every annotated property must resolve. No silent skip.
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

            throw new InjectionException(
                targetClass: $class,
                propertyName: $propName,
                propertyType: $typeName,
                injectionKind: $kind,
                message: "Cannot inject {$class}::\${$propName} (type: {$typeName}, "
                    . "kind: {$kind}). No binding found.",
            );
        }
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
