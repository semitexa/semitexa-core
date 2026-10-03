<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.handlerReturnsResponse
 *
 * TypedHandlerInterface::handle() must not have Response as return type.
 *
 * @implements Rule<ClassMethod>
 */
final class HandlerReturnsResponseRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: a handler that returns a Response bypasses the renderer, so content negotiation, the page-document path and the shell envelope never run for that route. Policy since the handler refactoring (2026-03); no incident on record.';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name->name !== 'handle') {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        if (!$classReflection->implementsInterface('Semitexa\\Core\\Contract\\TypedHandlerInterface')) {
            return [];
        }

        $returnType = $node->getReturnType();
        foreach ($this->collectReturnTypeNames($returnType) as $name) {
            if ($name !== 'Semitexa\\Core\\Response') {
                continue;
            }

            return [
                RuleErrorBuilder::message(
                    sprintf(
                        '%s::handle() must return a ResourceInterface, not a Response object. '
                        . 'Use domain exceptions for errors and resource DTO methods for data.',
                        $classReflection->getName(),
                    )
                )->tip(self::RATIONALE)->identifier('semitexa.handlerReturnsResponse')->build(),
            ];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function collectReturnTypeNames(Node\Name|Node\ComplexType|Node\Identifier|null $returnType): array
    {
        if ($returnType === null || $returnType instanceof Node\Identifier) {
            return [];
        }

        if ($returnType instanceof Node\Name) {
            return [$this->resolveName($returnType)];
        }

        if ($returnType instanceof Node\NullableType) {
            return $this->collectReturnTypeNames($returnType->type);
        }

        if ($returnType instanceof Node\UnionType) {
            $names = [];
            foreach ($returnType->types as $type) {
                $names = [...$names, ...$this->collectReturnTypeNames($type)];
            }

            return $names;
        }

        if ($returnType instanceof Node\IntersectionType) {
            $names = [];
            foreach ($returnType->types as $type) {
                $names = [...$names, ...$this->collectReturnTypeNames($type)];
            }

            return $names;
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
}
