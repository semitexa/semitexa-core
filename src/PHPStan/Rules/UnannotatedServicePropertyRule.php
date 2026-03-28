<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.unannotatedServiceProperty
 *
 * Flags protected properties typed as known injectable types (service interfaces,
 * Request, SessionInterface, etc.) that lack an #[InjectAs*] or #[Config] attribute
 * on container-managed framework objects.
 *
 * This catches the old pattern of relying on magic type-name injection.
 *
 * @implements Rule<Property>
 */
final class UnannotatedServicePropertyRule implements Rule
{
    private const KNOWN_INJECTABLE_TYPES = [
        'Semitexa\\Core\\Request',
        'Semitexa\\Core\\Session\\SessionInterface',
        'Semitexa\\Core\\Cookie\\CookieJarInterface',
        'Semitexa\\Core\\Tenant\\TenantContextInterface',
        'Semitexa\\Core\\Auth\\AuthContextInterface',
        'Semitexa\\Core\\Locale\\LocaleContextInterface',
        'Semitexa\\Core\\Event\\EventDispatcherInterface',
    ];

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

    private const CONTAINER_MANAGED_ATTRIBUTES = [
        'Semitexa\\Core\\Attributes\\AsService',
        'Semitexa\\Orm\\Attribute\\AsRepository',
        'Semitexa\\Core\\Attributes\\AsPayloadHandler',
        'Semitexa\\Core\\Attributes\\AsEventListener',
        'Semitexa\\Core\\Attributes\\AsPipelineListener',
        'Semitexa\\Core\\Attributes\\SatisfiesServiceContract',
        'Semitexa\\Core\\Attributes\\SatisfiesRepositoryContract',
        'Semitexa\\Core\\Attributes\\ExecutionScoped',
    ];

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->isProtected()) {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        // Only check container-managed framework objects
        if (!$this->isContainerManaged($classReflection)) {
            return [];
        }

        if ($this->hasInjectionAttribute($node)) {
            return [];
        }

        $type = $node->type;
        if ($type === null) {
            return [];
        }

        $typeName = null;
        if ($type instanceof Node\Name) {
            $typeName = $type->toString();
        }

        if ($typeName === null) {
            return [];
        }

        // Check if it ends with Interface or is a known injectable type
        $isKnownInjectable = in_array($typeName, self::KNOWN_INJECTABLE_TYPES, true)
            || str_ends_with($typeName, 'Interface');

        if (!$isKnownInjectable) {
            return [];
        }

        $propName = $node->props[0]->name->name ?? 'unknown';
        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Protected property $%s typed as %s on container-managed class %s lacks an injection attribute. '
                    . 'Add #[InjectAsReadonly] or #[InjectAsMutable] to explicitly declare injection. '
                    . 'Bare properties without injection attributes will not receive values.',
                    $propName,
                    $typeName,
                    $classReflection->getName(),
                )
            )->identifier('semitexa.unannotatedServiceProperty')->build(),
        ];
    }

    private function isContainerManaged(\PHPStan\Reflection\ClassReflection $classReflection): bool
    {
        $nativeReflection = $classReflection->getNativeReflection();
        foreach (self::CONTAINER_MANAGED_ATTRIBUTES as $attrClass) {
            if ($nativeReflection->getAttributes($attrClass) !== []) {
                return true;
            }
        }
        return false;
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
