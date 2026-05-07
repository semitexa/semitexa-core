<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\HttpResponse;

/**
 * Caches reflection metadata for TypedHandlerInterface handlers at boot time.
 * Populated once per worker, read-only during request handling — coroutine-safe.
 */
final class HandlerReflectionCache
{
    /** @var array<class-string<TypedHandlerInterface>, \ReflectionMethod> */
    private static array $methods = [];

    /**
     * Validate and cache the handle() method for a TypedHandlerInterface handler.
     * Called during discovery/boot phase.
     *
     * @throws ConfigurationException if the handler signature is invalid
     */
    public static function warm(string $handlerClass): void
    {
        if (!class_exists($handlerClass)) {
            throw new ConfigurationException("Handler class {$handlerClass} does not exist.");
        }

        if (!is_subclass_of($handlerClass, TypedHandlerInterface::class)) {
            throw new ConfigurationException("{$handlerClass} does not implement TypedHandlerInterface.");
        }

        $ref = new \ReflectionClass($handlerClass);

        if (!$ref->hasMethod('handle')) {
            throw new ConfigurationException("{$handlerClass} must declare a public handle() method.");
        }

        $method = $ref->getMethod('handle');

        if (!$method->isPublic()) {
            throw new ConfigurationException("{$handlerClass}::handle() must be public.");
        }

        $params = $method->getParameters();
        if (count($params) < 2) {
            throw new ConfigurationException("{$handlerClass}::handle() must accept at least 2 parameters (payload, resource).");
        }
        if (count($params) > 2) {
            for ($i = 2; $i < count($params); $i++) {
                if (!$params[$i]->isOptional()) {
                    throw new ConfigurationException(
                        "{$handlerClass}::handle() parameter {$i} must be optional. "
                        . "TypedHandlerInterface handlers only receive 2 parameters (payload, resource)."
                    );
                }
            }
        }

        // Validate parameter 0 (payload): one or more concrete class types.
        $payloadNames = self::namedTypes($params[0]->getType());
        if ($payloadNames === [] || self::anyBuiltin($payloadNames)) {
            throw new ConfigurationException("{$handlerClass}::handle() parameter 0 must be a concrete class type.");
        }

        // Validate parameter 1 (resource): one or more concrete ResourceInterface types.
        // Union types are accepted to support Accept-negotiation handlers that
        // declare e.g. `JsonResourceResponse|JsonLdResourceResponse|GraphqlResourceResponse`;
        // every member must implement ResourceInterface.
        $resourceNames = self::namedTypes($params[1]->getType());
        if ($resourceNames === [] || self::anyBuiltin($resourceNames)) {
            throw new ConfigurationException("{$handlerClass}::handle() parameter 1 must be a concrete ResourceInterface type.");
        }
        foreach ($resourceNames as $name) {
            if ($name === ResourceInterface::class) {
                continue;
            }
            if (!is_a($name, ResourceInterface::class, true)) {
                throw new ConfigurationException(
                    "{$handlerClass}::handle() parameter 1 type {$name} must implement ResourceInterface."
                );
            }
        }

        // Validate return type: must not be HttpResponse and should be ResourceInterface (or a union of them).
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            throw new ConfigurationException(
                "{$handlerClass}::handle() must declare a return type implementing ResourceInterface."
            );
        }
        $returnNames = self::namedTypes($returnType);
        foreach ($returnNames as $name) {
            if ($name === HttpResponse::class) {
                throw new ConfigurationException(
                    "{$handlerClass}::handle() must return a ResourceInterface, not a HttpResponse object."
                );
            }
            if ($name === 'object') {
                continue;
            }
            if (!is_a($name, ResourceInterface::class, true)) {
                throw new ConfigurationException(
                    "{$handlerClass}::handle() return type must implement ResourceInterface, got {$name}."
                );
            }
        }

        self::$methods[$handlerClass] = $method;
    }

    /**
     * Invoke the cached handle() method on a handler instance.
     *
     * @throws ConfigurationException if the handler was not warmed
     */
    public static function invoke(object $handler, object $payload, object $resource): object
    {
        $class = $handler::class;

        if (!isset(self::$methods[$class])) {
            throw new ConfigurationException("Handler {$class} not warmed. Call warm() at boot time.");
        }

        return self::$methods[$class]->invoke($handler, $payload, $resource);
    }

    /**
     * Check if a handler class has been warmed.
     */
    public static function has(string $handlerClass): bool
    {
        return isset(self::$methods[$handlerClass]);
    }

    /**
     * Reset all cached data. Used in tests.
     */
    public static function reset(): void
    {
        self::$methods = [];
    }

    /**
     * Flatten a parameter / return type into the list of concrete named types it accepts.
     * Returns [] for `null`/intersection types we cannot reason about. `?Foo` is
     * treated as `Foo` (nullability is irrelevant to the class-conformance check).
     *
     * @return list<string>
     */
    private static function namedTypes(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                if ($member instanceof \ReflectionNamedType && $member->getName() !== 'null') {
                    $names[] = $member->getName();
                }
            }
            return $names;
        }
        return [];
    }

    /**
     * @param list<string> $names
     */
    private static function anyBuiltin(array $names): bool
    {
        foreach ($names as $name) {
            if (in_array($name, ['int', 'float', 'string', 'bool', 'array', 'iterable', 'mixed', 'callable', 'void', 'never', 'null', 'false', 'true'], true)) {
                return true;
            }
        }
        return false;
    }
}
