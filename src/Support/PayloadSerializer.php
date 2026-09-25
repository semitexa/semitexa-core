<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

use ReflectionClass;

/**
 * Serialize Payload DTOs to/from array using getter/setter convention.
 * toArray: calls get*() for each getter; key = camelCase name without "get".
 * A bool is*() getter is included too when a matching set*() exists.
 * hydrate: for each key, calls set{CamelCase}($value) if method exists.
 *
 * Backed enums travel as their value and dates as DATE_ATOM strings;
 * hydrate turns them back using the setter's parameter type.
 */
class PayloadSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(object $dto): array
    {
        $reflection = new ReflectionClass($dto);
        $data = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'get') && strlen($name) > 3 && $method->getNumberOfRequiredParameters() === 0) {
                $key = lcfirst(substr($name, 3));
                $data[$key] = self::normalize($method->invoke($dto));
            } elseif (self::isBoolAccessor($method, $dto)) {
                $data[lcfirst(substr($name, 2))] = $method->invoke($dto);
            }
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function hydrate(object $dto, array $payload): object
    {
        $reflection = new ReflectionClass($dto);

        foreach ($payload as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $setterName = 'set' . ucfirst(Str::snakeToCamel($key));
            if (!method_exists($dto, $setterName)) {
                continue;
            }

            $method = $reflection->getMethod($setterName);
            if ($method->getNumberOfRequiredParameters() !== 1) {
                continue;
            }

            $method->invoke($dto, self::coerce($method->getParameters()[0], $value));
        }

        return $dto;
    }

    /**
     * isFoo(): bool paired with setFoo() is state, not a derived check, so
     * it must round-trip; without a setter hydrate could not restore it.
     */
    private static function isBoolAccessor(\ReflectionMethod $method, object $dto): bool
    {
        $name = $method->getName();
        if (!str_starts_with($name, 'is') || strlen($name) <= 2 || $method->getNumberOfRequiredParameters() !== 0) {
            return false;
        }
        $type = $method->getReturnType();

        return $type instanceof \ReflectionNamedType
            && $type->getName() === 'bool'
            && method_exists($dto, 'set' . substr($name, 2))
            // A getFoo() already supplies the key.
            && !method_exists($dto, 'get' . substr($name, 2));
    }

    /**
     * Reverse normalize() for enum and date setters. Anything else is
     * passed through as-is, as before.
     */
    private static function coerce(\ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !(is_string($value) || is_int($value))) {
            return $value;
        }
        $class = $type->getName();

        if (is_subclass_of($class, \BackedEnum::class)) {
            // from(), not tryFrom(): an unknown value must fail like any
            // other bad payload rather than silently become null.
            return $class::from($value);
        }
        if (is_string($value)) {
            if ($class === \DateTimeInterface::class) {
                return new \DateTimeImmutable($value);
            }
            if (is_a($class, \DateTimeInterface::class, true)) {
                return new $class($value);
            }
        }

        return $value;
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            is_object($value) => method_exists($value, '__toString')
                ? (string) $value
                : self::toArray($value),
            is_array($value) => array_map(fn ($item) => self::normalize($item), $value),
            default => $value,
        };
    }
}
