<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.factoryClosedWorld
 *
 * Factory-backed implementations are closed-world. The implementation, contract,
 * and enum key must belong to the same owning module namespace prefix.
 *
 * @implements Rule<Class_>
 */
final class FactoryClosedWorldRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        $native = $classReflection->getNativeReflection();
        $attrs = $native->getAttributes('Semitexa\\Core\\Attributes\\SatisfiesServiceContract');
        if ($attrs === []) {
            return [];
        }

        $errors = [];
        foreach ($attrs as $attr) {
            $instance = $attr->newInstance();
            if (!($instance->factoryKey instanceof \BackedEnum)) {
                continue;
            }

            $implPrefix = $this->modulePrefix($classReflection->getName());
            $contractPrefix = $this->modulePrefix(ltrim($instance->of, '\\'));
            $enumPrefix = $this->modulePrefix($instance->factoryKey::class);

            if ($implPrefix === null || $contractPrefix === null || $enumPrefix === null) {
                continue;
            }

            if ($implPrefix !== $contractPrefix || $implPrefix !== $enumPrefix) {
                $errors[] = RuleErrorBuilder::message(
                    sprintf(
                        'Factory-backed implementation %s must stay in the same owning module as contract %s and enum %s. '
                        . 'Closed-world factory contracts cannot be extended from another module.',
                        $classReflection->getName(),
                        ltrim($instance->of, '\\'),
                        $instance->factoryKey::class,
                    )
                )->identifier('semitexa.factoryClosedWorld')->build();
            }
        }

        return $errors;
    }

    private function modulePrefix(string $class): ?string
    {
        $parts = explode('\\', ltrim($class, '\\'));
        if (count($parts) < 2) {
            return null;
        }

        return $parts[0] . '\\' . $parts[1];
    }
}
