<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Http\Exception\TypeMismatchException;
use Semitexa\Core\Request;
use Semitexa\Core\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Hydrates Payload from HTTP Request using setter convention.
 *
 * For each key in raw data (JSON/POST/query + path params), calls set{CamelCase}($value)
 * if the method exists. Value is cast to the setter's parameter type before calling.
 * Path params are keyed by route param name (e.g. 'id' -> setId($value)).
 *
 * Strict mode: when enabled via enableStrictMode(true), type mismatches that cannot
 * be meaningfully coerced throw TypeMismatchException instead of silently casting.
 * Intended for use by semitexa-testing's InProcessTransport only.
 */
class PayloadHydrator
{
    private static bool $strictTypes = false;

    /**
     * Enable or disable strict type checking during hydration.
     * In strict mode, sending "hello" for an int field throws TypeMismatchException
     * instead of casting it to 0.
     *
     * NOTE: This flag is global (static). Only safe to toggle in single-process
     * test environments (PHPUnit CLI). Never enable in production or Swoole workers.
     */
    public static function enableStrictMode(bool $enabled = true): void
    {
        self::$strictTypes = $enabled;
    }

    public static function isStrictMode(): bool
    {
        return self::$strictTypes;
    }

    public static function hydrate(object $dto, Request $httpRequest): object
    {
        $pathParams = self::extractPathParams($dto, $httpRequest);
        $data = self::collectData($httpRequest);
        $data = array_merge($data, $pathParams);

        $reflection = new ReflectionClass($dto);

        foreach ($data as $key => $value) {
            $setterName = self::keyToSetterName($key);
            if (!method_exists($dto, $setterName)) {
                continue;
            }

            $method = $reflection->getMethod($setterName);
            if ($method->getNumberOfRequiredParameters() !== 1) {
                continue;
            }

            $param = $method->getParameters()[0];
            $type = $param->getType();
            $typedValue = self::castValue($value, $type, $key);
            $method->invoke($dto, $typedValue);
        }

        return $dto;
    }

    /**
     * Convert data key (snake_case or camelCase) to setter method name.
     */
    private static function keyToSetterName(string $key): string
    {
        $camel = Str::snakeToCamel($key);
        return 'set' . ucfirst($camel);
    }

    /**
     * Extract path parameters from URL; keys are route param names (e.g. 'id').
     */
    private static function extractPathParams(object $dto, Request $httpRequest): array
    {
        $reflection = new ReflectionClass($dto);
        $requestAttrs = $reflection->getAttributes(\Semitexa\Core\Attribute\AsPayload::class);
        if (empty($requestAttrs)) {
            return [];
        }

        try {
            $requestAttr = $requestAttrs[0]->newInstance();
            $routePattern = $requestAttr->path ?? null;
            $requirements = $requestAttr->requirements ?? [];
        } catch (\Throwable) {
            return [];
        }

        if ($routePattern === null || $routePattern === false || !is_string($routePattern) || $routePattern === '') {
            return [];
        }

        if (strpos($routePattern, '{') === false) {
            return [];
        }

        if (!preg_match_all('/\{([^}]+)\}/', $routePattern, $paramMatches)) {
            return [];
        }

        $pathParams = [];
        foreach ($paramMatches[1] as $index => $paramName) {
            $pathParams[$paramName] = $index;
        }

        $regexPattern = preg_quote($routePattern, '#');
        $regexPattern = preg_replace_callback(
            '#\\\{([^}]+)\\\}#',
            function ($m) use ($requirements) {
                $paramName = $m[1];
                return '(' . ($requirements[$paramName] ?? '[^/]+') . ')';
            },
            $regexPattern
        );
        $regexPattern = '#^' . $regexPattern . '$#';

        if (!preg_match($regexPattern, $httpRequest->getPath(), $matches)) {
            return [];
        }

        $params = [];
        foreach ($pathParams as $paramName => $groupIndex) {
            $captureIndex = $groupIndex + 1;
            if (isset($matches[$captureIndex])) {
                $params[$paramName] = $matches[$captureIndex];
            }
        }

        return $params;
    }

    public static function getRawData(Request $httpRequest, object $dto): array
    {
        return array_merge(self::collectData($httpRequest), self::extractPathParams($dto, $httpRequest));
    }

    private static function collectData(Request $httpRequest): array
    {
        $data = [];

        if ($httpRequest->isJson() && $httpRequest->getContent()) {
            $jsonData = $httpRequest->getJsonBody();
            if ($jsonData !== null) {
                $data = array_merge($data, $jsonData);
            } else {
                $content = $httpRequest->getContent();
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = array_merge($data, $decoded);
                }
            }
        } elseif ($httpRequest->isXml() && $httpRequest->getContent()) {
            $data = array_merge($data, self::xmlToArray($httpRequest->getContent()));
        } else {
            $data = array_merge($data, $httpRequest->post);
        }

        foreach ($httpRequest->query as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    private static function xmlToArray(string $xml): array
    {
        $element = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($element === false) {
            return [];
        }
        return json_decode(json_encode($element), true) ?: [];
    }

    private static function castValue(mixed $value, ?\ReflectionType $type, string $fieldName = ''): mixed
    {
        if ($type === null) {
            return $value;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($t->getName() !== 'null') {
                    return self::castToType($value, $t->getName(), $fieldName);
                }
            }
            return $value;
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            if ($type->allowsNull() && ($value === null || $value === '')) {
                return null;
            }
            return self::castToType($value, $typeName, $fieldName);
        }

        return $value;
    }

    private static function castToType(mixed $value, string $type, string $fieldName = ''): mixed
    {
        if ($value === null || $value === '') {
            return match ($type) {
                'int', 'float' => 0,
                'bool' => false,
                'string' => '',
                'array' => [],
                default => null,
            };
        }

        // Strict mode: reject values that cannot be meaningfully coerced to the target type.
        if (self::$strictTypes && !self::isTypeCompatible($value, $type)) {
            throw new TypeMismatchException($fieldName, $type, $value);
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => self::castToBool($value),
            'string' => (string) $value,
            'array' => is_array($value) ? $value : [$value],
            default => $value,
        };
    }

    /**
     * Determines whether $value can be meaningfully coerced to $type without semantic loss.
     * Used only in strict mode to guard against obviously wrong input types.
     */
    private static function isTypeCompatible(mixed $value, string $type): bool
    {
        return match ($type) {
            'int', 'float' => is_numeric($value) && !is_array($value),
            'string'       => is_scalar($value),
            'bool'         => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true),
            'array'        => is_array($value),
            default        => true, // Objects and unknown types: leave to PHP
        };
    }

    private static function castToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            return in_array($lower, ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }
}
