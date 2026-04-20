<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\Exception\InjectionException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Applies #[InjectAsReadonly] property injection to an already-constructed
 * instance by resolving each annotated property's type from the given
 * container.
 *
 * This is the runtime channel used for objects that are NOT part of the
 * container's readonly graph (for example Symfony Console commands, which
 * Semitexa instantiates but Symfony Application owns). Graph-managed
 * services receive the same injection during build via GraphBuilder; this
 * class exists to give commands and other "edge" objects exactly the same
 * property-injection contract without a separate DI model.
 *
 * Metadata (property → type) is cached per class so repeated injections
 * only pay reflection cost once per worker.
 */
final class PropertyInjector
{
    /** @var array<class-string, array<string, class-string>> */
    private static array $metadataCache = [];

    /**
     * Resolve and assign every #[InjectAsReadonly] property on $instance
     * from $container. Throws if a declared dependency is missing.
     */
    public static function inject(object $instance, ContainerInterface $container): void
    {
        $class = $instance::class;
        $metadata = self::metadata($class);

        $ref = new ReflectionClass($instance);
        foreach ($metadata as $propName => $typeName) {
            if (!$container->has($typeName)) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $propName,
                    propertyType: $typeName,
                    injectionKind: 'readonly',
                    message: "Cannot inject {$class}::\${$propName}: container has no binding for {$typeName}.",
                );
            }
            if ($container instanceof SemitexaContainer && $container->isExecutionScoped($typeName)) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $propName,
                    propertyType: $typeName,
                    injectionKind: 'readonly',
                    message: "Cannot inject execution-scoped {$typeName} into {$class}::\${$propName} with #[InjectAsReadonly]. "
                        . 'Use #[InjectAsMutable] on container-managed objects instead.',
                );
            }
            $value = $container->get($typeName);
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue($instance, $value);
        }
    }

    /**
     * Return the property → class-name map for a class, validating each
     * #[InjectAsReadonly] site against the same rules that apply to
     * graph-managed services (protected visibility, non-builtin class
     * or interface type, non-nullable).
     *
     * @param class-string $class
     * @return array<string, class-string>
     */
    public static function metadata(string $class): array
    {
        if (isset(self::$metadataCache[$class])) {
            return self::$metadataCache[$class];
        }

        $ref = new ReflectionClass($class);
        $out = [];

        foreach ($ref->getProperties() as $prop) {
            $attrs = $prop->getAttributes(InjectAsReadonly::class);
            if ($attrs === []) {
                continue;
            }

            if (!$prop->isProtected()) {
                $visibility = $prop->isPrivate() ? 'private' : 'public';
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: 'readonly',
                    message: "Cannot inject into {$visibility} property {$class}::\${$prop->getName()}. "
                        . "#[InjectAsReadonly] properties must be protected.",
                );
            }

            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $type,
                    injectionKind: 'readonly',
                    message: "#[InjectAsReadonly] property {$class}::\${$prop->getName()} "
                        . "must have a class or interface type, got: {$type}.",
                );
            }

            if ($type->allowsNull()) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $type,
                    injectionKind: 'readonly',
                    message: "#[InjectAsReadonly] property {$class}::\${$prop->getName()} "
                        . "must not be nullable. Dependencies are required contracts, not optional.",
                );
            }

            /** @var class-string $typeName */
            $typeName = $type->getName();
            $out[$prop->getName()] = $typeName;
        }

        return self::$metadataCache[$class] = $out;
    }

    /**
     * Clear the per-class metadata cache. Intended for tests that define
     * throwaway classes.
     */
    public static function clearCache(): void
    {
        self::$metadataCache = [];
    }
}
