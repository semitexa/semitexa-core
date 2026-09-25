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
        if (!(is_string($value) || is_int($value))) {
            return $value;
        }
        $type = $parameter->getType();
        // A union setter (`DateTimeImmutable|DateTime`, `Status|int`) is a
        // ReflectionUnionType: reading only named types passed the string on
        // unchanged and the setter threw a TypeError.
        $arms = $type instanceof \ReflectionNamedType ? [$type] : ($type instanceof \ReflectionUnionType ? $type->getTypes() : []);

        $classes = [];
        foreach ($arms as $arm) {
            if (!$arm instanceof \ReflectionNamedType) {
                continue;
            }
            if ($arm->isBuiltin()) {
                // A scalar arm that already takes the value keeps its meaning:
                // `DateTimeImmutable|string` given a string stays a string.
                if (self::builtinAccepts($arm->getName(), $value)) {
                    return $value;
                }
                continue;
            }
            $classes[] = $arm->getName();
        }

        // One class to aim at: a bad value fails loudly, as before. Several:
        // the first arm that accepts the value wins.
        $single = count($classes) === 1;
        foreach ($classes as $class) {
            $coerced = self::coerceTo($class, $value, $single);
            if ($coerced !== null) {
                return $coerced;
            }
        }

        return $value;
    }

    private static function builtinAccepts(string $builtin, string|int $value): bool
    {
        return match ($builtin) {
            'mixed' => true,
            'string' => is_string($value),
            'int', 'float' => is_int($value),
            default => false,
        };
    }

    /** The value as an instance of $class, or null when this arm does not take it. */
    private static function coerceTo(string $class, string|int $value, bool $strict): ?object
    {
        if (is_subclass_of($class, \BackedEnum::class)) {
            // from() when it is the only arm: an unknown value must fail like
            // any other bad payload rather than silently become null.
            return $strict ? $class::from($value) : $class::tryFrom($value);
        }
        if (!is_string($value) || !is_a($class, \DateTimeInterface::class, true)) {
            return null;
        }
        $concrete = $class === \DateTimeInterface::class ? \DateTimeImmutable::class : $class;
        if ($strict) {
            return new $concrete($value);
        }
        try {
            return new $concrete($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            // DATE_ATOM drops microseconds (10:15:30.123456 came back .000000),
            // so they are written when present; whole seconds keep the
            // DATE_ATOM wire format already sitting in queues and stores.
            $value instanceof \DateTimeInterface => $value->format('u') === '000000'
                ? $value->format(DATE_ATOM)
                : $value->format('Y-m-d\\TH:i:s.uP'),
            is_object($value) => method_exists($value, '__toString')
                ? (string) $value
                : self::toArray($value),
            is_array($value) => array_map(fn ($item) => self::normalize($item), $value),
            default => $value,
        };
    }
}
