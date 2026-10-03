<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.discoveryCallSite
 *
 * Flags AttributeDiscovery::initialize(), ClassDiscovery::initialize(),
 * ModuleRegistry::initialize() outside SemitexaContainer::build().
 *
 * @implements Rule<StaticCall>
 */
final class DiscoveryCallSiteRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: discovery is a full classmap scan meant to run once per worker, at boot. Learned 2026-07-11: lazy discovery under Swoole let the first concurrent burst after a worker boot enter the scan at once and hang the worker (two sites under load); a call outside SemitexaContainer::build() reopens that path.';

    private const DISCOVERY_CLASSES = [
        'Semitexa\\Core\\Discovery\\AttributeDiscovery',
        'Semitexa\\Core\\Discovery\\ClassDiscovery',
        'Semitexa\\Core\\ModuleRegistry',
        'AttributeDiscovery',
        'ClassDiscovery',
        'ModuleRegistry',
    ];

    /** Classes where initialize() calls are allowed */
    private const ALLOWED_CALLERS = [
        'Semitexa\\Core\\Container\\SemitexaContainer',
        'Semitexa\\Core\\Container\\ContainerBootstrapper',
        'Semitexa\\Core\\Container\\ServiceContractRegistry',
        'Semitexa\\Core\\Discovery\\AttributeDiscovery',
        'Semitexa\\Core\\Console\\Application',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier || $node->name->name !== 'initialize') {
            return [];
        }

        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $className = $node->class->toString();
        if (!in_array($className, self::DISCOVERY_CLASSES, true)) {
            return [];
        }

        $currentClass = $scope->getClassReflection()?->getName() ?? '';
        foreach (self::ALLOWED_CALLERS as $allowed) {
            if ($currentClass === $allowed) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    '%s::initialize() must only be called inside SemitexaContainer::build(). '
                    . 'Discovery runs exactly once during boot.',
                    $className,
                )
            )->tip(self::RATIONALE)->identifier('semitexa.discoveryCallSite')->build(),
        ];
    }
}
