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
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: the container refuses a public or private injection point with an InjectionException when it first builds the class; this rule moves that failure from the first boot to analysis. Policy since 2026-03-28; no incident on record.';

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
            )->tip(self::RATIONALE)->identifier('semitexa.injectedPropertyVisibility')->build(),
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
