<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\GraphBuilder;

/**
 * Builds execution-scoped prototype instances in topological dependency order.
 * Prototypes are cloned per request with execution context injected.
 *
 * Preconditions: context->executionScopedClasses, injections, instanceStore->readonly, idToClass, injectionAnalyzer must be populated.
 * Postconditions: instanceStore->prototypes populated; context->idToClass may have new entries.
 */
final class ExecutionScopedBuildPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->injectionAnalyzer !== null, 'ScopeDetectionPhase must run before ExecutionScopedBuildPhase');

        $graphBuilder = new GraphBuilder();
        $resolveToClass = $context->resolveToClass(...);

        $graphBuilder->buildExecutionScopedPrototypes(
            $context->executionScopedClasses,
            $context->injections,
            $context->instanceStore->readonly,
            $context->idToClass,
            $context->instanceStore->prototypes,
            $resolveToClass,
            $context->injectionAnalyzer,
        );
    }

    public function name(): string
    {
        return 'ExecutionScopedBuild';
    }
}
