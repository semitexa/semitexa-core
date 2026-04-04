<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.executionScopedWithoutAttribute
 *
 * Flags container-managed classes that use #[InjectAsMutable] properties but lack
 * an explicit scoping attribute (#[ExecutionScoped], #[AsPayloadHandler], etc.).
 *
 * Without a scoping attribute, the container treats the class as readonly (worker-scoped),
 * which means #[InjectAsMutable] properties would not receive per-request values.
 *
 * SCOPING_ATTRIBUTES are matched against the literal source spelling returned by
 * $attr->name->toString(). Aliased imports such as `use ExecutionScoped as ES;`
 * are therefore not resolved by this rule today.
 *
 * @implements Rule<Class_>
 */
final class ExecutionScopedWithoutAttributeRule implements Rule
{
    private const SCOPING_ATTRIBUTES = [
        'Semitexa\\Core\\Attributes\\ExecutionScoped',
        'Semitexa\\Core\\Attributes\\AsPayloadHandler',
        'Semitexa\\Core\\Attributes\\AsEventListener',
        'Semitexa\\Core\\Attributes\\AsPipelineListener',
        'ExecutionScoped',
        'AsPayloadHandler',
        'AsEventListener',
        'AsPipelineListener',
    ];

    private const MUTABLE_INJECTION_ATTRIBUTES = [
        'Semitexa\\Core\\Attributes\\InjectAsMutable',
        'InjectAsMutable',
    ];

    private const CONTAINER_MANAGED_ATTRIBUTES = [
        'Semitexa\\Core\\Attributes\\AsService',
        'Semitexa\\Orm\\Attribute\\AsRepository',
        'Semitexa\\Core\\Attributes\\SatisfiesServiceContract',
        'Semitexa\\Core\\Attributes\\SatisfiesRepositoryContract',
    ];

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->name?->name ?? '';
        if ($className === '') {
            return [];
        }

        // Skip if it already has a scoping attribute
        if ($this->hasScopingAttribute($node)) {
            return [];
        }

        // Only flag container-managed classes
        if (!$this->isContainerManaged($node)) {
            return [];
        }

        // Only flag if the class uses #[InjectAsMutable] on any property
        if (!$this->hasMutableInjection($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Class %s uses #[InjectAsMutable] but lacks an explicit scoping attribute. '
                    . 'Add #[ExecutionScoped], #[AsPayloadHandler], #[AsEventListener], or #[AsPipelineListener] '
                    . 'so the container knows to clone this class per request.',
                    $className,
                )
            )->identifier('semitexa.executionScopedWithoutAttribute')->build(),
        ];
    }

    private function hasScopingAttribute(Class_ $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (in_array($attr->name->toString(), self::SCOPING_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isContainerManaged(Class_ $node): bool
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (in_array($attr->name->toString(), self::CONTAINER_MANAGED_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function hasMutableInjection(Class_ $node): bool
    {
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Property) {
                continue;
            }
            foreach ($stmt->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    if (in_array($attr->name->toString(), self::MUTABLE_INJECTION_ATTRIBUTES, true)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
