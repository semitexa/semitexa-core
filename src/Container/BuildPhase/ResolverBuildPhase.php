<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\GraphBuilder;

/**
 * Builds resolver instances (generated registry resolvers with constructor injection).
 *
 * Preconditions: context->contractDetails, idToClass, instanceStore->readonly, prototypes, injections, injectionAnalyzer must be populated.
 * Postconditions: resolver instances added to instanceStore->readonly.
 */
final class ResolverBuildPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->injectionAnalyzer !== null, 'ScopeDetectionPhase must run before ResolverBuildPhase');

        $graphBuilder = new GraphBuilder();

        $graphBuilder->buildResolvers(
            $context->contractDetails,
            $context->idToClass,
            $context->instanceStore->readonly,
            $context->instanceStore->prototypes,
            $context->injections,
            $context->injectionAnalyzer,
        );
    }

    public function name(): string
    {
        return 'ResolverBuild';
    }
}
