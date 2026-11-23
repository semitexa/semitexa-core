<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\ServiceContractRegistry;

/**
 * Resolves service contracts: discovers interface→implementation mappings,
 * and populates idToClass for concrete classes and contract bindings. The
 * registry's active implementation is the binding: no generated resolver is
 * asked (d-20261123-di-registry-one-source).
 *
 * Preconditions: context->classDiscovery, context->moduleRegistry must be set.
 * Postconditions: context->contractDetails, context->idToClass (partial) populated.
 */
final class ContractResolutionPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->classDiscovery !== null, 'ClassmapLoadPhase must run before ContractResolutionPhase');
        assert($context->moduleRegistry !== null, 'ModuleDiscoveryPhase must run before ContractResolutionPhase');

        $registry = new ServiceContractRegistry($context->classDiscovery, $context->moduleRegistry);
        $context->contractDetails = $registry->getContractDetails();

        foreach ($context->contractDetails as $interface => $data) {
            $active = $data['active'];
            foreach ($data['implementations'] as $impl) {
                $implClass = $impl['class'];
                $context->idToClass[$implClass] = $implClass;
            }
            $context->idToClass[$interface] = $active;
        }
    }

    public function name(): string
    {
        return 'ContractResolution';
    }
}
