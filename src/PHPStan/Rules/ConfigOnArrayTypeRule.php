<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.configOnArrayType
 *
 * Flags #[Config] on properties typed as `array`. Arrays are forbidden — use a typed DTO.
 *
 * @implements Rule<Property>
 */
final class ConfigOnArrayTypeRule implements Rule
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
        if ($type instanceof Node\Identifier && $type->name === 'array') {
            $propName = $node->props[0]->name->name ?? 'unknown';
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        '#[Config] property $%s must not be typed as array. '
                        . 'Arrays have no schema and cannot be validated at boot. '
                        . 'Use a typed DTO or collection object injected via #[InjectAsReadonly] instead.',
                        $propName,
                    )
                )->identifier('semitexa.configOnArrayType')->build(),
            ];
        }

        return [];
    }

    private function hasConfigAttribute(Property $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();
                if ($name === 'Semitexa\\Core\\Attributes\\Config' || $name === 'Config') {
                    return true;
                }
            }
        }
        return false;
    }
}
