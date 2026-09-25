<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\GraphBuilder;
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
                // stay uninitialized forever. Except where that is the contract:
                // an optional dependency with NO binding stays uninitialized on
                // every class, and graph build and injection already skip it.
                if ($kind === 'mutable' && !isset($context->executionScopedClasses[$class])
                    && !(!empty($info['optional']) && !self::mutableIsBound($context, $typeName))) {
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

                // Soft dependency (optional: true): skipped at injection, so not a
                // boot error — when nothing implements it. When an #[ExecutionScoped]
                // class does, a binding exists that can never be injected here;
                // GraphBuilder rejects that input, and so must validation.
                $trap = GraphBuilder::describeExecutionScopedTrap($typeName, $context->idToClass, $context->executionScopedClasses);
                if (!empty($info['optional']) && $trap === '') {
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
                        . "(type: {$typeName}, kind: {$kind}) has no binding."
                        . $trap,
                );
            }
        }
    }

    /** Whether a mutable injection of this type has anything to be filled from. */
    private static function mutableIsBound(BuildContext $context, string $typeName): bool
    {
        return isset($context->instanceStore->prototypes[$typeName])
            || isset($context->instanceStore->prototypes[$context->idToClass[$typeName] ?? ''])
            || in_array($typeName, SemitexaContainer::EXECUTION_CONTEXT_TYPES, true);
    }

    public function name(): string
    {
        return 'Validation';
    }
}
