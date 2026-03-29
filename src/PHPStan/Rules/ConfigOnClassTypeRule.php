<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.configOnClassType
 *
 * Flags #[Config] on properties typed as class or interface (must be scalar or backed enum).
 *
 * @implements Rule<Property>
 */
final class ConfigOnClassTypeRule implements Rule
{
    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->hasConfigAttribute($node)) {
            return [];
        }

        $type = $node->type;
        if ($type === null) {
            return [];
        }

        if ($type instanceof Node\Identifier) {
            $name = $type->name;
            if (in_array($name, ['int', 'float', 'string', 'bool'], true)) {
                return [];
            }
            if ($name === 'array') {
                return []; // Caught by ConfigOnArrayTypeRule
            }
            return [];
        }

        if ($type instanceof Node\Name) {
            $resolved = $this->resolveName($type);
            // Check if it's a class/interface (not a scalar)
            if (class_exists($resolved) || interface_exists($resolved)) {
                // Allow backed enums
                if (enum_exists($resolved) && is_subclass_of($resolved, \BackedEnum::class)) {
                    return [];
                }
                $propName = $node->props[0]->name->name ?? 'unknown';
                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            '#[Config] property $%s has class/interface type %s. '
                            . '#[Config] only supports int, float, string, bool, and backed enums. '
                            . 'For service dependencies, use #[InjectAsReadonly] instead.',
                            $propName,
                            $resolved,
                        )
                    )->identifier('semitexa.configOnClassType')->build(),
                ];
            }
        }

        return [];
    }

    private function resolveName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return $resolved->toString();
        }

        return $name->toString();
    }

    private function hasConfigAttribute(Property $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->toString() === 'Semitexa\\Core\\Attributes\\Config'
                    || $attr->name->toString() === 'Config') {
                    return true;
                }
            }
        }
        return false;
    }
}
