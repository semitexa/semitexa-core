<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\InjectionAnalyzer;

/**
 * Detects execution-scoped classes via #[ExecutionScoped] attribute and
 * implied scope from handler/listener attributes.
 *
 * Preconditions: context->classDiscovery, attributeDiscovery, idToClass must be populated.
 * Postconditions: context->executionScopedClasses populated, context->injectionAnalyzer set.
 */
final class ScopeDetectionPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->classDiscovery !== null, 'ClassmapLoadPhase must run before ScopeDetectionPhase');
        assert($context->attributeDiscovery !== null, 'AttributeScanPhase must run before ScopeDetectionPhase');

        $injectionAnalyzer = new InjectionAnalyzer($context->classDiscovery, $context->attributeDiscovery);
        $context->injectionAnalyzer = $injectionAnalyzer;
        $context->executionScopedClasses = $injectionAnalyzer->collectExecutionScopedClasses($context->idToClass);
    }

    public function name(): string
    {
        return 'ScopeDetection';
    }
}
