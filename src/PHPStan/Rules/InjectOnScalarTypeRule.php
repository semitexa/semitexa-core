<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.injectOnScalarType
 *
 * Flags #[InjectAsReadonly], #[InjectAsMutable], #[InjectAsFactory] on properties
 * with scalar types. Must use #[Config] instead.
 *
 * @implements Rule<Property>
 */
final class InjectOnScalarTypeRule implements Rule
{
    private const INJECT_ATTRIBUTES = [
        'Semitexa\\Core\\Attributes\\InjectAsReadonly',
        'Semitexa\\Core\\Attributes\\InjectAsMutable',
        'Semitexa\\Core\\Attributes\\InjectAsFactory',
        'InjectAsReadonly',
        'InjectAsMutable',
        'InjectAsFactory',
    ];

    private const SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'array'];

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->hasInjectAttribute($node)) {
            return [];
        }

        $type = $node->type;
        if ($type instanceof Node\Identifier && in_array($type->name, self::SCALAR_TYPES, true)) {
            $propName = $node->props[0]->name->name ?? 'unknown';
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Injected property $%s has scalar type %s. '
                        . '#[InjectAs*] attributes require class or interface types. '
                        . 'For scalar configuration values, use #[Config] instead.',
                        $propName,
                        $type->name,
                    )
                )->identifier('semitexa.injectOnScalarType')->build(),
            ];
        }

        return [];
    }

    private function hasInjectAttribute(Property $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (in_array($attr->name->toString(), self::INJECT_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }
        return false;
    }
}
