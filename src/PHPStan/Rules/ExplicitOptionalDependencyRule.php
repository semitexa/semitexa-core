<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.explicitOptionalDependency
 *
 * Flags class_exists() for Semitexa package classes. Package absence must not be
 * modeled through runtime checks — use explicit module ownership and required bindings.
 *
 * @implements Rule<FuncCall>
 */
final class ExplicitOptionalDependencyRule implements Rule
{
    /** Namespaces where class_exists() for Semitexa classes is allowed */
    private const ALLOWED_NAMESPACES = [
        'Semitexa\\Core\\Container\\',
        'Semitexa\\Core\\Application',
        'Semitexa\\Core\\Console\\',
        'Semitexa\\Core\\Discovery\\',
        'Semitexa\\Core\\Pipeline\\',
        'Semitexa\\Core\\Server\\',
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Name) {
            return [];
        }

        $funcName = $node->name->toString();
        if ($funcName !== 'class_exists') {
            return [];
        }

        $args = $node->getArgs();
        if (count($args) === 0) {
            return [];
        }

        $arg = $args[0]->value;
        $className = null;

        if ($arg instanceof Node\Expr\ClassConstFetch
            && $arg->name instanceof Node\Identifier
            && $arg->name->name === 'class'
            && $arg->class instanceof Node\Name) {
            $className = $arg->class->toString();
        } elseif ($arg instanceof Node\Scalar\String_) {
            $className = $arg->value;
        }

        if ($className === null) {
            return [];
        }

        // Only flag Semitexa namespace classes
        if (!str_starts_with($className, 'Semitexa\\')) {
            return [];
        }

        // Allow in core framework internals
        $currentClass = $scope->getClassReflection()?->getName() ?? '';
        foreach (self::ALLOWED_NAMESPACES as $allowed) {
            if (str_starts_with($currentClass, $allowed)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'class_exists(%s) is forbidden for Semitexa package classes in application code. '
                    . 'Package capabilities must be satisfied through explicit module ownership and '
                    . 'required contract bindings, not runtime class_exists() checks.',
                    $className,
                )
            )->identifier('semitexa.explicitOptionalDependency')->build(),
        ];
    }
}
