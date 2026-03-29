<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.moduleContractOwnership
 *
 * Flags module-level capability interfaces declared in Semitexa\Core\Contract.
 * Capability contracts must live in their owning module, not in core waiting
 * for another package to satisfy them later.
 *
 * @implements Rule<Interface_>
 */
final class ModuleContractOwnershipRule implements Rule
{
    public function getNodeType(): string
    {
        return Interface_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace() ?? '';
        $name = $node->name?->toString() ?? '';
        $isCoreContractNamespace = $namespace === 'Semitexa\\Core\\Contract'
            || str_starts_with($namespace, 'Semitexa\\Core\\Contract\\');

        if (!$isCoreContractNamespace || $name === '') {
            return [];
        }

        // Narrow to capability-style contracts that should be module-owned.
        $looksCapabilityLike = str_ends_with($name, 'BootstrapperInterface')
            || str_contains($name, 'Auth')
            || str_contains($name, 'Locale')
            || str_contains($name, 'Tenant');

        if (!$looksCapabilityLike) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Capability contract %s\\%s must not live in semitexa-core. '
                    . 'Move it to the owning module and ship at least one implementation there.',
                    $namespace,
                    $name,
                )
            )->identifier('semitexa.moduleContractOwnership')->build(),
        ];
    }
}
