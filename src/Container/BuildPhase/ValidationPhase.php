<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\SemitexaContainer;

/**
 * Validates that all injection bindings can be resolved at boot time.
 * Catches configuration errors early instead of at request time.
 *
 * Preconditions: all build phases complete — injections, idToClass, instanceStore fully populated.
 * Postconditions: none (validation only — throws on unresolvable binding).
 */
final class ValidationPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        foreach ($context->injections as $class => $properties) {
            foreach ($properties as $propName => $info) {
                $kind = $info['kind'];
                $typeName = $info['type'];

                // #[InjectAsMutable] is filled only on per-execution clones; a
                // worker-scoped instance is never cloned, so the property would
                // stay uninitialized forever.
                if ($kind === 'mutable' && !isset($context->executionScopedClasses[$class])) {
                    throw new InjectionException(
                        targetClass: $class,
                        propertyName: $propName,
                        propertyType: $typeName,
                        injectionKind: $kind,
                        message: "Boot validation failed: {$class}::\${$propName} "
                            . "(type: {$typeName}) is #[InjectAsMutable], but {$class} is worker-scoped, "
                            . 'so it would never be injected. Mark the class #[ExecutionScoped], '
                            . 'or inject a worker-scoped seam with #[InjectAsReadonly] and read per-execution state at call time.',
                    );
                }

                $resolved = match ($kind) {
                    'factory' => $context->instanceStore->factories[$typeName] ?? null,
                    'readonly' => $context->instanceStore->readonly[$typeName]
                        ?? $context->instanceStore->readonly[$context->idToClass[$typeName] ?? '']
                        ?? null,
                    'mutable' => $context->instanceStore->prototypes[$typeName]
                        ?? $context->instanceStore->prototypes[$context->idToClass[$typeName] ?? '']
                        ?? null,
                    default => null,
                };

                if ($resolved !== null) {
                    continue;
                }

                // Soft dependency (optional: true): skipped at injection, so not a boot error.
                if (!empty($info['optional'])) {
                    continue;
                }

                if ($kind === 'mutable' && in_array($typeName, SemitexaContainer::EXECUTION_CONTEXT_TYPES, true)) {
                    continue;
                }

                if ($kind === 'mutable' && isset($context->executionScopedClasses[$class])) {
                    $protoClass = $context->idToClass[$typeName] ?? $typeName;
                    if (isset($context->instanceStore->prototypes[$protoClass]) || isset($context->instanceStore->prototypes[$typeName])) {
                        continue;
                    }
                }

                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $propName,
                    propertyType: $typeName,
                    injectionKind: $kind,
                    message: "Boot validation failed: {$class}::\${$propName} "
                        . "(type: {$typeName}, kind: {$kind}) has no binding.",
                );
            }
        }
    }

    public function name(): string
    {
        return 'Validation';
    }
}
