<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.executionScopedWithoutAttribute
 *
 * Class names containing "Handler" or "Listener" should use explicit attributes
 * for execution scoping, not rely on name-string matching.
 *
 * This is a transitional rule that flags classes whose names suggest they should
 * be execution-scoped but lack the explicit attribute or handler/listener annotation.
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

        // Only check classes that look like they need execution scoping
        if (!str_contains($className, 'Handler') && !str_contains($className, 'Listener')) {
            return [];
        }

        // Skip if it already has a scoping attribute
        if ($this->hasScopingAttribute($node)) {
            return [];
        }

        // Only flag container-managed classes (has AsService or SatisfiesServiceContract)
        if (!$this->isContainerManaged($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Class %s appears to be execution-scoped (name contains "Handler" or "Listener") '
                    . 'but lacks an explicit scoping attribute. Add #[ExecutionScoped], #[AsPayloadHandler], '
                    . '#[AsEventListener], or #[AsPipelineListener]. '
                    . 'Name-string matching is no longer used for scope detection.',
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
}
