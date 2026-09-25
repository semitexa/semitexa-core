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
        if (!str_starts_with($name, 'is') || strlen($name) <= 2 || $method->isStatic() || $method->getNumberOfRequiredParameters() !== 0) {
            return false;
        }
        $type = $method->getReturnType();
        if (!$type instanceof \ReflectionNamedType || $type->getName() !== 'bool') {
            return false;
        }
        // A getFoo() already supplies the key.
        if (method_exists($dto, 'get' . substr($name, 2))) {
            return false;
        }

        // Serialized only if hydrate() can put it back: a public, non-static
        // setter taking one argument. A private or static setFoo() made the
        // flag go out and never come back — the payload changed in transit.
        $setter = 'set' . substr($name, 2);
        if (!method_exists($dto, $setter)) {
            return false;
        }
        $setterMethod = new \ReflectionMethod($dto, $setter);

        return $setterMethod->isPublic() && !$setterMethod->isStatic() && $setterMethod->getNumberOfRequiredParameters() === 1;
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
        // Only the wire format normalize() writes. The date constructor reads
        // '' as now and 'tomorrow' or '+1 day' as real dates, so a stray string
        // silently became a timestamp nobody sent.
        $parsed = self::parseWireDate($value);
        if ($parsed === null) {
            if ($strict) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot restore %s from %s: dates are serialized as DATE_ATOM, with microseconds when present.',
                    $class,
                    var_export($value, true),
                ));
            }

            return null;
        }

        // Built from the parsed value, not the constructor: the constructor
        // cannot read the five-digit year normalize() writes for year 10000.
        return $concrete::createFromInterface($parsed);
    }

    /**
     * Exactly the shapes normalize() writes — DATE_ATOM, with or without
     * microseconds — for ANY year it can write. `X` is the expanded year:
     * `Y` parses four digits at most, so year 10000 or a negative year
     * came back rejected by the serializer that wrote it. `!` keeps fields
     * the string does not carry (microseconds) at zero, not the current time.
     */
    private static function parseWireDate(string $value): ?\DateTimeImmutable
    {
        foreach (['!X-m-d\\TH:i:sP', '!X-m-d\\TH:i:s.uP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            $clean = $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
            if ($parsed !== false && $clean) {
                return $parsed;
            }
        }

        return null;
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
