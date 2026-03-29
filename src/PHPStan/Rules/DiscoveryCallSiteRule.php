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
            )->identifier('semitexa.discoveryCallSite')->build(),
        ];
    }
}
