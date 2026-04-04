<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Container\Exception\CircularDependencyException;

/**
 * DFS-based circular dependency detection for the container's dependency graph.
 *
 * @internal Used only by ContainerBootstrapper during build.
 */
final class CycleDetector
{
    /**
     * Assert no circular dependencies in the full dependency graph (both readonly and execution-scoped).
     * Uses DFS with cycle detection. Throws CircularDependencyException with full chain.
     *
     * @param array<string, string> $idToClass
     * @param array<string, true> $executionScopedClasses
     * @param array<string, array<string, array{kind: string, type: string}>> $injections
     * @param callable(string): ?string $resolveToClass
     */
    public function assertNoCycles(
        array $idToClass,
        array $executionScopedClasses,
        array $injections,
        callable $resolveToClass,
    ): void {
        // Build adjacency list from injection metadata
        $allClasses = array_unique(array_merge(
            array_values(array_filter($idToClass, fn($id) => !interface_exists($id), ARRAY_FILTER_USE_KEY)),
            array_keys($executionScopedClasses),
        ));

        $adjacency = [];
        foreach ($allClasses as $class) {
            $adjacency[$class] = [];
            $classInjections = $injections[$class] ?? [];
            foreach ($classInjections as $info) {
                if ($info['kind'] === 'factory') {
                    continue; // Factories don't participate in cycle detection
                }
                $target = $resolveToClass($info['type']);
                if ($target !== null && $target !== $class) {
                    $adjacency[$class][] = $target;
                }
            }
        }

        // DFS-based cycle detection
        $white = []; // unvisited
        $gray = [];  // in current path
        $black = []; // fully processed

        foreach (array_keys($adjacency) as $node) {
            $white[$node] = true;
        }

        foreach (array_keys($adjacency) as $node) {
            if (isset($white[$node])) {
                $this->dfsDetectCycle($node, $adjacency, $white, $gray, $black, []);
            }
        }
    }

    /**
     * @param array<string, list<string>> $adjacency
     * @param array<string, true> $white
     * @param array<string, true> $gray
     * @param array<string, true> $black
     * @param list<string> $path
     */
    private function dfsDetectCycle(
        string $node,
        array $adjacency,
        array &$white,
        array &$gray,
        array &$black,
        array $path,
    ): void {
        unset($white[$node]);
        $gray[$node] = true;
        $path[] = $node;

        foreach ($adjacency[$node] ?? [] as $neighbor) {
            if (isset($black[$neighbor])) {
                continue;
            }
            if (isset($gray[$neighbor])) {
                // Found a cycle — extract the cycle path
                $cycleStart = array_search($neighbor, $path, true);
                if ($cycleStart === false) {
                    throw new \LogicException("Cycle detection invariant violated: {$neighbor} is gray but missing from path.");
                }
                $chain = array_slice($path, $cycleStart);
                $chain[] = $neighbor;
                throw new CircularDependencyException(
                    chain: $chain,
                    message: 'Circular dependency detected: ' . implode(' -> ', $chain),
                );
            }
            if (isset($white[$neighbor])) {
                $this->dfsDetectCycle($neighbor, $adjacency, $white, $gray, $black, $path);
            }
        }

        unset($gray[$node]);
        $black[$node] = true;
    }
}
