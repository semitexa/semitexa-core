<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Analyzes class properties for injection metadata and validates injection rules.
 * Also handles #[Config] property resolution and execution-scoped class detection.
 *
 * @internal Used only by ContainerBootstrapper during build.
 */
final class InjectionAnalyzer
{
    private ?ClassDiscovery $classDiscovery = null;
    private ?AttributeDiscovery $attributeDiscovery = null;

    /**
     * @internal Called by ContainerBootstrapper before collectExecutionScopedClasses.
     */
    public function setDiscoveryInstances(ClassDiscovery $classDiscovery, AttributeDiscovery $attributeDiscovery): void
    {
        $this->classDiscovery = $classDiscovery;
        $this->attributeDiscovery = $attributeDiscovery;
    }

    /**
     * Collect execution-scoped classes by explicit #[ExecutionScoped] attribute
     * and implied by handler/listener attributes. No name-string fallback.
     *
     * @param array<string, string> $idToClass
     * @return array<string, true>
     */
    public function collectExecutionScopedClasses(array $idToClass): array
    {
        $executionScopedClasses = [];

        // Explicit #[ExecutionScoped] attribute
        foreach ($idToClass as $id => $class) {
            if (interface_exists($id)) {
                continue;
            }
            try {
                $ref = new ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }
            if ($ref->getAttributes(ExecutionScoped::class) !== []) {
                $executionScopedClasses[$class] = true;
            }
        }

        // Implied by #[AsPayloadHandler]
        foreach ($this->attributeDiscovery->getDiscoveredPayloadHandlerClassNames() as $handlerClass) {
            $executionScopedClasses[$handlerClass] = true;
        }

        // Implied by #[AsEventListener]
        foreach ($this->classDiscovery->findClassesWithAttribute(AsEventListener::class) as $listenerClass) {
            $executionScopedClasses[$listenerClass] = true;
        }

        // Implied by #[AsPipelineListener]
        foreach ($this->classDiscovery->findClassesWithAttribute(AsPipelineListener::class) as $listenerClass) {
            $executionScopedClasses[$listenerClass] = true;
        }

        // Auth handlers are execution-scoped
        if (class_exists(\Semitexa\Auth\Attribute\AsAuthHandler::class)) {
            foreach ($this->classDiscovery->findClassesWithAttribute(\Semitexa\Auth\Attribute\AsAuthHandler::class) as $handlerClass) {
                $executionScopedClasses[$handlerClass] = true;
            }
        }

        return $executionScopedClasses;
    }

    /**
     * Collect injection metadata for all registered classes.
     *
     * @param array<string, string> $idToClass
     * @param array<string, true> $executionScopedClasses
     * @return array<string, array<string, array{kind: string, type: string}>>
     */
    public function collectInjections(array $idToClass, array $executionScopedClasses): array
    {
        $seen = [];
        foreach ($idToClass as $id => $class) {
            if (!interface_exists($id)) {
                $seen[$class] = true;
            }
        }
        foreach (array_keys($executionScopedClasses) as $class) {
            $seen[$class] = true;
        }

        $injections = [];
        foreach (array_keys($seen) as $class) {
            $injections[$class] = $this->buildInjectionsForClass($class);
        }

        return $injections;
    }

    /**
     * Build injection metadata for a single class with strict validation.
     *
     * @return array<string, array{kind: string, type: string}>
     */
    public function buildInjectionsForClass(string $class): array
    {
        $out = [];
        try {
            $ref = new ReflectionClass($class);
        } catch (\Throwable) {
            return [];
        }

        foreach ($ref->getProperties() as $prop) {
            $injectAttrs = [
                ...array_map(fn($a) => ['attr' => $a, 'kind' => 'readonly'],
                    $prop->getAttributes(InjectAsReadonly::class)),
                ...array_map(fn($a) => ['attr' => $a, 'kind' => 'mutable'],
                    $prop->getAttributes(InjectAsMutable::class)),
                ...array_map(fn($a) => ['attr' => $a, 'kind' => 'factory'],
                    $prop->getAttributes(InjectAsFactory::class)),
            ];

            $configAttrs = $prop->getAttributes(Config::class);

            if (empty($injectAttrs) && empty($configAttrs)) {
                continue;
            }

            if (count($injectAttrs) > 1) {
                $kinds = implode(', ', array_map(static fn(array $attr): string => $attr['kind'], $injectAttrs));
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: $injectAttrs[0]['kind'],
                    message: "Property {$class}::\${$prop->getName()} declares multiple #[InjectAs*] attributes ({$kinds}). "
                        . 'Choose exactly one injection mode.',
                );
            }

            if (!empty($injectAttrs) && !empty($configAttrs)) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: $injectAttrs[0]['kind'],
                    message: "Property {$class}::\${$prop->getName()} cannot combine #[Config] with #[InjectAs*]. "
                        . 'Choose configuration injection or service injection, but not both.',
                );
            }

            // Validate: no #[InjectAs*] or #[Config] inside traits
            $declaringTrait = $this->findDeclaringTraitForProperty($ref, $prop->getName());
            if ($declaringTrait !== null) {
                $attrName = !empty($injectAttrs) ? '#[InjectAs*]' : '#[Config]';
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: !empty($injectAttrs) ? $injectAttrs[0]['kind'] : 'config',
                    message: "{$attrName} is forbidden inside traits. Property {$declaringTrait}::\${$prop->getName()} "
                        . "must be moved to the consuming class {$class}.",
                );
            }

            // Validate visibility: protected is the only allowed visibility
            if (!$prop->isProtected()) {
                $visibility = $prop->isPrivate() ? 'private' : 'public';
                $attrKind = !empty($injectAttrs) ? $injectAttrs[0]['kind'] : 'config';
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: $attrKind,
                    message: "Cannot inject into {$visibility} property {$class}::\${$prop->getName()}. "
                        . "Injected properties must be protected. No exceptions.",
                );
            }

            // Handle #[Config] properties — skip for injection metadata (handled separately)
            if (!empty($configAttrs)) {
                continue;
            }

            // Validate type for #[InjectAs*]: must be class or interface type
            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $type,
                    injectionKind: $injectAttrs[0]['kind'],
                    message: "Injected property {$class}::\${$prop->getName()} must have "
                        . "a class or interface type, got: {$type}. "
                        . "For scalar configuration values, use #[Config] instead.",
                );
            }

            $out[$prop->getName()] = [
                'kind' => $injectAttrs[0]['kind'],
                'type' => $type->getName(),
            ];
        }

        return $out;
    }

    /**
     * Inject #[Config] properties from environment variables or defaults.
     */
    public function injectConfigProperties(object $instance, string $class, ReflectionClass $ref): void
    {
        foreach ($ref->getProperties() as $prop) {
            $configAttrs = $prop->getAttributes(Config::class);
            if (empty($configAttrs)) {
                continue;
            }

            // Visibility already validated in collectInjections, but validate here too
            // for classes not yet collected (e.g. during prototype building)
            if (!$prop->isProtected()) {
                $visibility = $prop->isPrivate() ? 'private' : 'public';
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: 'config',
                    message: "Cannot inject config into {$visibility} property {$class}::\${$prop->getName()}. "
                        . "Config properties must be protected.",
                );
            }

            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $type,
                    injectionKind: 'config',
                    message: "#[Config] property {$class}::\${$prop->getName()} must have a named scalar type "
                        . "(int, float, string, bool, or a backed enum), got: {$type}.",
                );
            }

            $typeName = $type->getName();

            // Arrays are forbidden
            if ($typeName === 'array') {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: 'array',
                    injectionKind: 'config',
                    message: "#[Config] property {$class}::\${$prop->getName()} must not be typed as array. "
                        . "Arrays have no schema and cannot be validated at boot. "
                        . "Use a typed DTO or collection object injected via #[InjectAsReadonly] instead.",
                );
            }

            // Must be a scalar builtin or a backed enum
            if ($type->isBuiltin() && !in_array($typeName, ['int', 'float', 'string', 'bool'], true)) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: $typeName,
                    injectionKind: 'config',
                    message: "#[Config] property {$class}::\${$prop->getName()} has unsupported type: {$typeName}. "
                        . "Allowed types: int, float, string, bool, or a backed enum.",
                );
            }

            if (!$type->isBuiltin()) {
                // Non-builtin: must be a backed enum
                if (!class_exists($typeName) && !interface_exists($typeName)) {
                    throw new InjectionException(
                        targetClass: $class,
                        propertyName: $prop->getName(),
                        propertyType: $typeName,
                        injectionKind: 'config',
                        message: "#[Config] property {$class}::\${$prop->getName()} has unresolvable type: {$typeName}.",
                    );
                }
                $ref2 = new ReflectionClass($typeName);
                if (!$ref2->isEnum() || !$ref2->implementsInterface(\BackedEnum::class)) {
                    throw new InjectionException(
                        targetClass: $class,
                        propertyName: $prop->getName(),
                        propertyType: $typeName,
                        injectionKind: 'config',
                        message: "#[Config] property {$class}::\${$prop->getName()} has class type: {$typeName}. "
                            . "#[Config] only supports int, float, string, bool, and backed enums. "
                            . "For service dependencies, use #[InjectAsReadonly] instead.",
                    );
                }
            }

            /** @var Config $config */
            $config = $configAttrs[0]->newInstance();
            $value = $config->env !== null
                ? $this->resolveEnvValue($config->env, $config->default, $typeName)
                : $config->default;

            $prop->setAccessible(true);
            $prop->setValue($instance, $value);
        }
    }

    /**
     * Resolve an environment variable value, casting to the target type.
     */
    public function resolveEnvValue(string $envKey, int|float|string|bool|null $default, string $targetType): int|float|string|bool|null
    {
        $raw = Environment::getEnvValue($envKey);
        if ($raw === null) {
            return $default;
        }

        return match ($targetType) {
            'int' => (int) $raw,
            'float' => (float) $raw,
            'string' => $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            default => $this->resolveEnvBackedEnum($raw, $targetType, $default),
        };
    }

    private function resolveEnvBackedEnum(string $raw, string $enumClass, mixed $default): mixed
    {
        if (!enum_exists($enumClass) || !is_subclass_of($enumClass, \BackedEnum::class)) {
            return $default;
        }
        return $enumClass::tryFrom($raw) ?? $default;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function findDeclaringTraitForProperty(ReflectionClass $class, string $propertyName): ?string
    {
        foreach ($class->getTraits() as $trait) {
            if ($trait->hasProperty($propertyName)) {
                return $trait->getName();
            }

            $nested = $this->findDeclaringTraitForProperty($trait, $propertyName);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
