<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * semitexa.factoryStringKey
 *
 * Flags string arguments to factory get() methods — must use backed enum.
 *
 * @implements Rule<MethodCall>
 */
final class FactoryStringKeyRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: a string key cannot be checked: a typo resolves to nothing at runtime, and no analyser can list the keys that exist. Policy since 2026-03-28; no incident on record.';

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier || $node->name->name !== 'get') {
            return [];
        }

        $callerType = $scope->getType($node->var);

        // Check if the caller implements ContractFactoryInterface
        $factoryType = new ObjectType('Semitexa\\Core\\Contract\\ContractFactoryInterface');
        if (!$factoryType->isSuperTypeOf($callerType)->yes()) {
            return [];
        }

        $args = $node->getArgs();
        if (count($args) === 0) {
            return [];
        }

        $argValue = $args[0]->value;
        if ($argValue instanceof Node\Scalar\String_) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Factory get() must use a backed enum, not a string key "%s". '
                        . 'String arguments to factory get() are forbidden.',
                        $argValue->value,
                    )
                )->tip(self::RATIONALE)->identifier('semitexa.factoryStringKey')->build(),
            ];
        }

        return [];
    }
}
