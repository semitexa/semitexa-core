<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\GraphBuilder;

/**
 * Builds readonly (worker-scoped) service instances in topological dependency order.
 *
 * Preconditions: context->idToClass, executionScopedClasses, injections, instanceStore, injectionAnalyzer must be populated.
 * Postconditions: instanceStore->readonly populated with all readonly service instances.
 */
final class ReadonlyBuildPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->injectionAnalyzer !== null, 'ScopeDetectionPhase must run before ReadonlyBuildPhase');

        $graphBuilder = new GraphBuilder();
        $resolveToClass = $context->resolveToClass(...);

        $graphBuilder->buildReadonlyGraph(
            $context->idToClass,
            $context->executionScopedClasses,
            $context->injections,
            $context->instanceStore->readonly,
            $resolveToClass,
            $context->injectionAnalyzer,
        );
    }

    public function name(): string
    {
        return 'ReadonlyBuild';
    }
}
