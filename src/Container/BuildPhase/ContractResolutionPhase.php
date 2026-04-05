<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\GraphBuilder;
use Semitexa\Core\Container\ServiceContractRegistry;

/**
 * Resolves service contracts: discovers interface→implementation mappings,
 * populates idToClass for concrete classes and contract bindings,
 * and identifies resolver classes.
 *
 * Preconditions: context->classDiscovery, context->moduleRegistry must be set.
 * Postconditions: context->contractDetails, context->idToClass (partial), context->interfaceToResolver populated.
 */
final class ContractResolutionPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->classDiscovery !== null, 'ClassmapLoadPhase must run before ContractResolutionPhase');
        assert($context->moduleRegistry !== null, 'ModuleDiscoveryPhase must run before ContractResolutionPhase');

        $registry = new ServiceContractRegistry($context->classDiscovery, $context->moduleRegistry);
        $context->contractDetails = $registry->getContractDetails();

        $graphBuilder = new GraphBuilder();

        foreach ($context->contractDetails as $interface => $data) {
            $active = $data['active'];
            foreach ($data['implementations'] as $impl) {
                $implClass = $impl['class'];
                $context->idToClass[$implClass] = $implClass;
            }
            $context->idToClass[$interface] = $active;

            $resolverClass = $graphBuilder->getResolverClassForContract($interface);
            if ($resolverClass !== null && class_exists($resolverClass)) {
                $context->idToClass[$resolverClass] = $resolverClass;
                $context->interfaceToResolver[$interface] = $resolverClass;
            }
        }
    }

    public function name(): string
    {
        return 'ContractResolution';
    }
}
