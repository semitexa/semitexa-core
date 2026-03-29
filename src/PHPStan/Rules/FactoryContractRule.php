<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.factoryContract
 *
 * Flags factory interfaces (name starts with "Factory") that do not extend
 * ContractFactoryInterface or whose get() signature is not enum-keyed.
 *
 * @implements Rule<Interface_>
 */
final class FactoryContractRule implements Rule
{
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {}

    public function getNodeType(): string
    {
        return Interface_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name?->name ?? '';
        if (!str_starts_with($name, 'Factory')) {
            return [];
        }

        // Check if it extends ContractFactoryInterface
        $extendsFactory = false;
        foreach ($node->extends as $extend) {
            $extendName = $extend->toString();
            if ($extendName === 'Semitexa\\Core\\Contract\\ContractFactoryInterface'
                || $extendName === 'ContractFactoryInterface') {
                $extendsFactory = true;
                break;
            }
        }

        if (!$extendsFactory) {
            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'Factory interface %s must extend ContractFactoryInterface.',
                        $name,
                    )
                )->identifier('semitexa.factoryContract')->build(),
            ];
        }

        $hasGetMethod = false;
        foreach ($node->getMethods() as $method) {
            if ($method->name->name !== 'get') {
                continue;
            }
            $hasGetMethod = true;

            $params = $method->getParams();
            if (count($params) !== 1) {
                return [
                    RuleErrorBuilder::message(
                        sprintf('Factory interface %s::get() must accept exactly one backed enum parameter.', $name)
                    )->identifier('semitexa.factoryContract')->build(),
                ];
            }

            $type = $params[0]->type;
            if ($type instanceof Node\NullableType || $type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            'Factory interface %s::get() parameter must be a single backed enum type; union/intersection/nullable types are not supported.',
                            $name,
                        )
                    )->identifier('semitexa.factoryContract')->build(),
                ];
            }

            if (!$type instanceof Node\Name) {
                return [
                    RuleErrorBuilder::message(
                        sprintf('Factory interface %s::get() parameter must be a backed enum type.', $name)
                    )->identifier('semitexa.factoryContract')->build(),
                ];
            }

            $typeName = $this->resolveTypeName($type);
            $builtinTypes = ['string', 'int', 'float', 'bool', 'array', 'object', 'mixed', 'callable', 'iterable', 'resource', 'null', 'void', 'never', 'static', 'self'];
            if (in_array($typeName, $builtinTypes, true) || str_contains($typeName, '|') || str_contains($typeName, '&')) {
                return [
                    RuleErrorBuilder::message(
                        sprintf('Factory interface %s::get() must use a backed enum parameter, not %s.', $name, $typeName)
                    )->identifier('semitexa.factoryContract')->build(),
                ];
            }

            if (!$this->isBackedEnumTypeName($typeName)) {
                return [
                    RuleErrorBuilder::message(
                        sprintf('Factory interface %s::get() parameter must resolve to a backed enum, got %s.', $name, $typeName)
                    )->identifier('semitexa.factoryContract')->build(),
                ];
            }

            return [];
        }

        return $hasGetMethod ? [] : [];
    }

    private function resolveTypeName(Node\Name $type): string
    {
        $resolved = $type->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return $resolved->toString();
        }

        return $type->toString();
    }

    private function isBackedEnumTypeName(string $typeName): bool
    {
        if (!$this->reflectionProvider->hasClass($typeName)) {
            return false;
        }

        $classReflection = $this->reflectionProvider->getClass($typeName);

        return $classReflection->isEnum() && $classReflection->isBackedEnum();
    }
}
