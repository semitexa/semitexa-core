<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

use ReflectionClass;

/**
 * Serialize Payload DTOs to/from array using getter/setter convention.
 * toArray: calls get*() for each getter; key = camelCase name without "get".
 * hydrate: for each key, calls set{CamelCase}($value) if method exists.
 */
class PayloadSerializer
{
    public static function toArray(object $dto): array
    {
        $reflection = new ReflectionClass($dto);
        $data = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'get') && strlen($name) > 3 && $method->getNumberOfRequiredParameters() === 0) {
                $key = lcfirst(substr($name, 3));
                $data[$key] = self::normalize($method->invoke($dto));
            }
        }

        return $data;
    }

    public static function hydrate(object $dto, array $payload): object
    {
        $reflection = new ReflectionClass($dto);

        foreach ($payload as $key => $value) {
            $setterName = 'set' . ucfirst(Str::snakeToCamel($key));
            if (!method_exists($dto, $setterName)) {
                continue;
            }

            $method = $reflection->getMethod($setterName);
            if ($method->getNumberOfRequiredParameters() !== 1) {
                continue;
            }

            $method->invoke($dto, $value);
        }

        return $dto;
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            is_object($value) => method_exists($value, '__toString')
                ? (string) $value
                : self::toArray($value),
            is_array($value) => array_map(fn ($item) => self::normalize($item), $value),
            default => $value,
        };
    }
}
