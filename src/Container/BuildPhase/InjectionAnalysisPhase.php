<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

/**
 * Collects injection metadata (#[InjectAsReadonly], #[InjectAsMutable], etc.)
 * for all registered classes.
 *
 * Preconditions: context->idToClass, context->executionScopedClasses, context->injectionAnalyzer must be populated.
 * Postconditions: context->injections populated.
 */
final class InjectionAnalysisPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->injectionAnalyzer !== null, 'ScopeDetectionPhase must run before InjectionAnalysisPhase');

        $context->injections = $context->injectionAnalyzer->collectInjections(
            $context->idToClass,
            $context->executionScopedClasses,
        );
    }

    public function name(): string
    {
        return 'InjectionAnalysis';
    }
}
