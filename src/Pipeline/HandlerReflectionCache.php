<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Response;

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
     * @throws \LogicException if the handler signature is invalid
     */
    public static function warm(string $handlerClass): void
    {
        if (!class_exists($handlerClass)) {
            throw new \LogicException("Handler class {$handlerClass} does not exist.");
        }

        if (!is_subclass_of($handlerClass, TypedHandlerInterface::class)) {
            throw new \LogicException("{$handlerClass} does not implement TypedHandlerInterface.");
        }

        $ref = new \ReflectionClass($handlerClass);

        if (!$ref->hasMethod('handle')) {
            throw new \LogicException("{$handlerClass} must declare a public handle() method.");
        }

        $method = $ref->getMethod('handle');

        if (!$method->isPublic()) {
            throw new \LogicException("{$handlerClass}::handle() must be public.");
        }

        $params = $method->getParameters();
        if (count($params) < 2) {
            throw new \LogicException("{$handlerClass}::handle() must accept at least 2 parameters (payload, resource).");
        }

        // Validate parameter 0: must be a concrete class type (not built-in)
        $payloadType = $params[0]->getType();
        if (!$payloadType instanceof \ReflectionNamedType || $payloadType->isBuiltin()) {
            throw new \LogicException("{$handlerClass}::handle() parameter 0 must be a concrete class type.");
        }

        // Validate parameter 1: concrete ResourceInterface
        $resourceType = $params[1]->getType();
        if (!$resourceType instanceof \ReflectionNamedType || $resourceType->isBuiltin()) {
            throw new \LogicException("{$handlerClass}::handle() parameter 1 must be a concrete ResourceInterface type.");
        }
        if (!is_subclass_of($resourceType->getName(), ResourceInterface::class) && $resourceType->getName() !== ResourceInterface::class) {
            throw new \LogicException(
                "{$handlerClass}::handle() parameter 1 type {$resourceType->getName()} must implement ResourceInterface."
            );
        }

        // Validate return type: must not be Response
        $returnType = $method->getReturnType();
        if ($returnType instanceof \ReflectionNamedType && $returnType->getName() === Response::class) {
            throw new \LogicException(
                "{$handlerClass}::handle() must return a ResourceInterface, not a Response object."
            );
        }

        self::$methods[$handlerClass] = $method;
    }

    /**
     * Invoke the cached handle() method on a handler instance.
     *
     * @throws \LogicException if the handler was not warmed
     */
    public static function invoke(object $handler, object $payload, object $resource): object
    {
        $class = $handler::class;

        if (!isset(self::$methods[$class])) {
            throw new \LogicException("Handler {$class} not warmed. Call warm() at boot time.");
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
}
