<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.readonlyInjectionOfExecutionScoped
 *
 * An #[InjectAsReadonly] property whose type is execution-scoped: a class the
 * container clones per request (#[ExecutionScoped], or implied by
 * #[AsPayloadHandler], #[AsEventListener], #[AsPipelineListener], an auth or a
 * slot handler). PropertyInjector refuses it at runtime, and boot validation
 * refuses it on a container-managed class; this names it at analysis time, on
 * any class, with the same fix (tk-inject-from-context-attr).
 *
 * Only a concrete class type is judged: which class an interface resolves to
 * is the container's knowledge, and lint:di asks the container for it.
 *
 * @implements Rule<Property>
 */
final class ReadonlyInjectionOfExecutionScopedRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: an execution-scoped class is cloned per request, while #[InjectAsReadonly] fills a property once for the object\'s whole life, so the object would keep the first request\'s instance; PropertyInjector refuses it at runtime, this says it before the code runs. Policy since 2026-11-15; no incident on record.';

    private const READONLY_INJECTION = 'Semitexa\\Core\\Attribute\\InjectAsReadonly';

    /** What makes a class execution-scoped; InjectionAnalyzer::collectExecutionScopedClasses() is the source. */
    private const SCOPING_ATTRIBUTES = [
        'Semitexa\\Core\\Attribute\\ExecutionScoped',
        'Semitexa\\Core\\Attribute\\AsPayloadHandler',
        'Semitexa\\Core\\Attribute\\AsEventListener',
        'Semitexa\\Core\\Attribute\\AsPipelineListener',
        'Semitexa\\Auth\\Attribute\\AsAuthHandler',
        'Semitexa\\Ssr\\Attribute\\AsSlotHandler',
    ];

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // ?Type is still that type: PropertyInjector refuses it alike (review, core#238).
        $declared = $node->type instanceof NullableType ? $node->type->type : $node->type;
        if (!$this->hasReadonlyInjection($node, $scope) || !$declared instanceof Name) {
            return [];
        }
        $typeName = $scope->resolveName($declared);
        if (!$this->reflectionProvider->hasClass($typeName)) {
            return [];
        }
        $type = $this->reflectionProvider->getClass($typeName);
        if ($type->isInterface() || !$this->isExecutionScoped($type)) {
            return [];
        }

        $class = $scope->getClassReflection()?->getName() ?? '';
        $errors = [];
        foreach ($node->props as $prop) {
            $errors[] = RuleErrorBuilder::message(
                "Cannot inject execution-scoped {$typeName} into {$class}::\${$prop->name->toString()} with #[InjectAsReadonly]. "
                . 'Use #[InjectAsMutable] on container-managed objects instead.',
            )->tip(self::RATIONALE)->identifier('semitexa.readonlyInjectionOfExecutionScoped')->build();
        }

        return $errors;
    }

    private function hasReadonlyInjection(Property $node, Scope $scope): bool
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($scope->resolveName($attr->name) === self::READONLY_INJECTION) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isExecutionScoped(ClassReflection $class): bool
    {
        foreach ($class->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), self::SCOPING_ATTRIBUTES, true)) {
                return true;
            }
        }

        return false;
    }
}
