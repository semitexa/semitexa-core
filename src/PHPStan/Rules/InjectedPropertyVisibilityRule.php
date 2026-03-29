<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.injectedPropertyVisibility
 *
 * Flags #[InjectAs*] or #[Config] on any property that is not `protected`.
 *
 * @implements Rule<Property>
 */
final class InjectedPropertyVisibilityRule implements Rule
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
        if (!$this->hasInjectionAttribute($node)) {
            return [];
        }

        if ($node->isProtected()) {
            return [];
        }

        $visibility = $node->isPrivate() ? 'private' : 'public';
        $propName = $node->props[0]->name->name ?? 'unknown';

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Cannot inject into %s property $%s. '
                    . 'Injected properties must be protected. No exceptions.',
                    $visibility,
                    $propName,
                )
            )->identifier('semitexa.injectedPropertyVisibility')->build(),
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
