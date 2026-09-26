<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\GraphBuilder;

/**
 * Builds contract factory instances for contracts with multiple implementations,
 * then injects factories into execution-scoped prototypes that use #[InjectAsFactory].
 *
 * Preconditions: context->contractDetails, interfaceToResolver, instanceStore (readonly + prototypes),
 *                injections must be populated.
 * Postconditions: instanceStore->factories populated; factory references injected into prototypes.
 */
final class FactoryBuildPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        $graphBuilder = new GraphBuilder();

        $graphBuilder->buildFactories(
            $context->contractDetails,
            $context->interfaceToResolver,
            $context->instanceStore->readonly,
            $context->instanceStore->prototypes,
            $context->instanceStore->factories,
            $context->resolveService ?? throw new \LogicException('FactoryBuildPhase needs BuildContext::$resolveService.'),
            $context->instanceStore->genericFactories,
        );

        $graphBuilder->injectFactoriesIntoPrototypes(
            $context->instanceStore->prototypes,
            $context->injections,
            $context->instanceStore->factories,
            $context->instanceStore->genericFactories,
        );

        // Worker-scoped services are never cloned, so this is their only
        // chance to receive an #[InjectAsFactory] property.
        $graphBuilder->injectFactoriesIntoPrototypes(
            $context->instanceStore->readonly,
            $context->injections,
            $context->instanceStore->factories,
            $context->instanceStore->genericFactories,
        );

        // Their initialize() was held back until now, so it never reads an
        // #[InjectAsFactory] property before it is assigned.
        $graphBuilder->initializeAfterFactoryInjection(
            $context->instanceStore->readonly,
            $context->injections,
        );
    }

    public function name(): string
    {
        return 'FactoryBuild';
    }
}
