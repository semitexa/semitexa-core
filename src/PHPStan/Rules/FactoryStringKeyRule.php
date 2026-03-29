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
                )->identifier('semitexa.factoryStringKey')->build(),
            ];
        }

        return [];
    }
}
