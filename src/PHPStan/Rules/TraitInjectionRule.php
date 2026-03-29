<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.traitInjection
 *
 * Flags #[InjectAsReadonly], #[InjectAsMutable], #[InjectAsFactory], or #[Config]
 * inside trait definitions. All injected dependencies must be declared directly
 * in the consuming class.
 *
 * @implements Rule<Property>
 */
final class TraitInjectionRule implements Rule
{
    private const INJECTION_ATTRIBUTES = [
        'Semitexa\\Core\\Attributes\\InjectAsReadonly',
        'Semitexa\\Core\\Attributes\\InjectAsMutable',
        'Semitexa\\Core\\Attributes\\InjectAsFactory',
        'Semitexa\\Core\\Attributes\\Config',
        'InjectAsReadonly',
        'InjectAsMutable',
        'InjectAsFactory',
        'Config',
    ];

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$scope->isInTrait()) {
            return [];
        }

        if (!$this->hasInjectionAttribute($node)) {
            return [];
        }

        $propName = $node->props[0]->name->name ?? 'unknown';
        $traitName = $scope->getTraitReflection()?->getName() ?? 'unknown trait';

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Injection attributes are forbidden inside traits. '
                    . 'Property %s::$%s must be moved to the consuming class. '
                    . 'Traits may contain pure logic methods but not injected properties.',
                    $traitName,
                    $propName,
                )
            )->identifier('semitexa.traitInjection')->build(),
        ];
    }

    private function hasInjectionAttribute(Property $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (in_array($attr->name->toString(), self::INJECTION_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }
        return false;
    }
}
