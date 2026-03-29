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
    private const CONTRACT_FACTORY_INTERFACE = 'Semitexa\\Core\\Contract\\ContractFactoryInterface';

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

        $interfaceName = $this->resolveInterfaceName($node, $scope);
        $extendsFactory = $this->extendsContractFactoryInterface($node, $interfaceName);

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

            return $this->validateAstGetMethod($name, $method);
        }

        if ($hasGetMethod) {
            return [];
        }

        return $this->validateInheritedGetMethod($name, $interfaceName);
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

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function validateAstGetMethod(string $interfaceName, Node\Stmt\ClassMethod $method): array
    {
        $params = $method->getParams();
        if (count($params) !== 1) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() must accept exactly one backed enum parameter.', $interfaceName))];
        }

        $type = $params[0]->type;
        if ($type instanceof Node\NullableType || $type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return [$this->buildGetMethodError(sprintf(
                'Factory interface %s::get() parameter must be a single backed enum type; union/intersection/nullable types are not supported.',
                $interfaceName,
            ))];
        }

        if (!$type instanceof Node\Name) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() parameter must be a backed enum type.', $interfaceName))];
        }

        $typeName = $this->resolveTypeName($type);
        $builtinTypes = ['string', 'int', 'float', 'bool', 'array', 'object', 'mixed', 'callable', 'iterable', 'resource', 'null', 'void', 'never', 'static', 'self'];
        if (in_array($typeName, $builtinTypes, true) || str_contains($typeName, '|') || str_contains($typeName, '&')) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() must use a backed enum parameter, not %s.', $interfaceName, $typeName))];
        }

        if ($typeName === \BackedEnum::class) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() must use a concrete backed enum parameter, not %s.', $interfaceName, $typeName))];
        }

        if (!$this->isBackedEnumTypeName($typeName)) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() parameter must resolve to a backed enum, got %s.', $interfaceName, $typeName))];
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function validateInheritedGetMethod(string $shortName, string $interfaceName): array
    {
        if (!$this->reflectionProvider->hasClass($interfaceName)) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() must declare or inherit exactly one backed enum parameter.', $shortName))];
        }

        try {
            $interfaceRef = $this->reflectionProvider->getClass($interfaceName)->getNativeReflection();
            $params = $interfaceRef->getMethod('get')->getParameters();
        } catch (\ReflectionException $e) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() must declare or inherit exactly one backed enum parameter.', $shortName))];
        }

        if (count($params) !== 1) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() must accept exactly one backed enum parameter.', $shortName))];
        }

        $type = $params[0]->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() parameter must be a concrete backed enum type.', $shortName))];
        }

        $typeName = ltrim($type->getName(), '\\');
        if ($typeName === \BackedEnum::class) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() must not inherit generic %s; override it with a concrete backed enum.', $shortName, \BackedEnum::class))];
        }

        if (!$this->isBackedEnumTypeName($typeName)) {
            return [$this->buildGetMethodError(sprintf('Factory interface %s::get() parameter must resolve to a backed enum, got %s.', $shortName, $typeName))];
        }

        return [];
    }

    private function extendsContractFactoryInterface(Interface_ $node, string $interfaceName): bool
    {
        foreach ($node->extends as $extend) {
            $extendName = $this->resolveTypeName($extend);
            if ($extendName === self::CONTRACT_FACTORY_INTERFACE || $extendName === 'ContractFactoryInterface') {
                return true;
            }
        }

        if (!$this->reflectionProvider->hasClass($interfaceName)) {
            return false;
        }

        return $this->reflectionProvider->getClass($interfaceName)->isSubclassOf(self::CONTRACT_FACTORY_INTERFACE);
    }

    private function resolveInterfaceName(Interface_ $node, Scope $scope): string
    {
        $resolved = $node->namespacedName ?? null;
        if ($resolved instanceof Node\Name) {
            return $resolved->toString();
        }

        $shortName = $node->name?->toString() ?? '';
        $namespace = $scope->getNamespace();

        return $namespace !== null ? $namespace . '\\' . $shortName : $shortName;
    }

    private function buildGetMethodError(string $message): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('semitexa.factoryContract')
            ->build();
    }
}
