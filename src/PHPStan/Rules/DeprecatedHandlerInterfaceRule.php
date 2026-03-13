<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports classes that implement the deprecated HandlerInterface.
 *
 * @implements Rule<Class_>
 */
final class DeprecatedHandlerInterfaceRule implements Rule
{
    private const DEPRECATED_INTERFACE = 'Semitexa\\Core\\Contract\\HandlerInterface';
    private const REPLACEMENT_INTERFACE = 'Semitexa\\Core\\Contract\\TypedHandlerInterface';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * @param Class_ $node
     * @return list<\PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->namespacedName === null) {
            return [];
        }

        foreach ($node->implements as $implement) {
            $implementedName = $implement->toString();

            if ($implementedName === self::DEPRECATED_INTERFACE) {
                return [
                    RuleErrorBuilder::message(sprintf(
                        'Class %s implements deprecated %s. Migrate to %s. '
                        . 'See the Handler Refactoring Technical Design for migration steps.',
                        $node->namespacedName->toString(),
                        self::shortClass(self::DEPRECATED_INTERFACE),
                        self::shortClass(self::REPLACEMENT_INTERFACE),
                    ))
                        ->identifier('semitexa.deprecatedHandlerInterface')
                        ->build(),
                ];
            }
        }

        return [];
    }

    private static function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
