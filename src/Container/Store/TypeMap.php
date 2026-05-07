<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\Store;

/**
 * Maps type identifiers to concrete classes. Replaces the overloaded idToClass array
 * with two purpose-specific maps: contractBindings (interface → concrete) and
 * registeredClasses (known concrete classes).
 *
 * @internal Used only by SemitexaContainer and ContainerBootstrapper.
 */
final class TypeMap
{
    /** @var array<string, class-string> Interface → active concrete class */
    public array $contractBindings = [];

    /** @var array<class-string, true> All registered concrete classes */
    public array $registeredClasses = [];

    /** @var array<string, class-string> Interface → resolver class (dynamic resolution) */
    public array $interfaceToResolver = [];

    /** @var array<class-string, true> Execution-scoped classes (cloned per request) */
    public array $executionScoped = [];

    /**
     * Interface → every registered concrete impl (in module-rank order, active first).
     *
     * Mirrors the existing `contractBindings` map but preserves the full chain
     * for additive contracts whose semantics are "every implementation
     * contributes" (PermissionProviderInterface, CapabilityProviderInterface).
     * The active winner of `SatisfiesServiceContract` election is index 0;
     * remaining impls are recorded in declaration order so consumers can
     * iterate without losing the deterministic ranking.
     *
     * @var array<string, list<class-string>>
     */
    public array $allContractImplementations = [];

    /**
     * Populate from the legacy idToClass array used during build.
     * Splits entries: self-mappings → registeredClasses, others → contractBindings.
     *
     * @param array<string, class-string> $idToClass
     * @param array<class-string, true> $executionScopedClasses
     * @param array<string, class-string> $interfaceToResolver
     * @param array<string, list<class-string>> $allContractImplementations
     */
    public function populateFromBuildArrays(
        array $idToClass,
        array $executionScopedClasses,
        array $interfaceToResolver,
        array $allContractImplementations = [],
    ): void {
        $this->contractBindings = [];
        $this->registeredClasses = [];
        $this->interfaceToResolver = [];
        $this->executionScoped = [];
        $this->allContractImplementations = [];

        $this->interfaceToResolver = $interfaceToResolver;
        $this->executionScoped = $executionScopedClasses;
        $this->allContractImplementations = $allContractImplementations;

        foreach ($idToClass as $id => $class) {
            if ($id === $class) {
                $this->registeredClasses[$class] = true;
            } else {
                $this->contractBindings[$id] = $class;
                // Ensure the concrete class is also registered
                $this->registeredClasses[$class] = true;
            }
        }
    }

    /**
     * Resolve an id (concrete class or interface) to its concrete class.
     *
     * Resolution order:
     * 1. Direct concrete class (in registeredClasses)
     * 2. Interface → concrete (in contractBindings)
     *
     * @return class-string|null
     */
    public function resolveClass(string $id): ?string
    {
        if (isset($this->registeredClasses[$id])) {
            /** @var class-string $class */
            $class = $id;
            return $class;
        }

        return $this->contractBindings[$id] ?? null;
    }

    /**
     * Check whether a service id is known (as concrete class, contract binding, or resolver).
     */
    public function isKnown(string $id): bool
    {
        return isset($this->registeredClasses[$id])
            || isset($this->contractBindings[$id])
            || isset($this->interfaceToResolver[$id]);
    }

    /**
     * Check whether a class is execution-scoped.
     * Resolves the id to a concrete class first if needed.
     */
    public function isExecutionScoped(string $id): bool
    {
        $class = $this->resolveClass($id) ?? $id;

        return isset($this->executionScoped[$class]);
    }
}
