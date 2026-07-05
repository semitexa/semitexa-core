<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.staticContainerAccess
 *
 * Flags ContainerFactory:: calls outside Semitexa core/runtime internals.
 * Any usage in application modules, handlers, services, listeners, repositories,
 * or package business logic is an error.
 *
 * @implements Rule<StaticCall>
 */
final class StaticContainerAccessRule implements Rule
{
    /**
     * Namespaces where ContainerFactory usage is allowed — core internals,
     * plus the narrow dynamic-dispatch tier: infrastructure whose whole job
     * is resolving an arbitrary, runtime-named #[AsService] class (queue
     * consumers, the scheduler's job executor). Dynamic dispatch is what the
     * container is FOR; there is no attribute-injection shape for a class
     * name that only exists in a database row. Entries here are exact class
     * prefixes — bless the dispatch chokepoint, never a whole package.
     */
    private const ALLOWED_NAMESPACES = [
        'Semitexa\\Core\\Container\\',
        'Semitexa\\Core\\Log\\StaticLoggerBridge',
        'Semitexa\\Core\\Application',
        'Semitexa\\Core\\Console\\',
        'Semitexa\\Core\\Server\\',
        'Semitexa\\Core\\Event\\EventDispatcher',
        'Semitexa\\Core\\Queue\\',
        'Semitexa\\Scheduler\\Application\\Service\\RunExecutor',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $className = $node->class->toString();
        if ($className !== 'Semitexa\\Core\\Container\\ContainerFactory'
            && $className !== 'ContainerFactory') {
            return [];
        }

        $currentClass = $scope->getClassReflection()?->getName() ?? '';
        $currentNamespace = $scope->getNamespace() ?? '';

        foreach (self::ALLOWED_NAMESPACES as $allowed) {
            if (str_starts_with($currentClass, $allowed) || str_starts_with($currentNamespace . '\\', $allowed)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Static ContainerFactory:: access is forbidden in application code (%s). '
                    . 'Use #[InjectAsReadonly], #[InjectAsMutable], #[InjectAsFactory] property injection instead.',
                    $currentClass ?: $currentNamespace,
                )
            )->identifier('semitexa.staticContainerAccess')->build(),
        ];
    }
}
