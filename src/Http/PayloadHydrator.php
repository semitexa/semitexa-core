<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Http\Exception\TypeMismatchException;
use Semitexa\Core\Http\UploadedFile;
use Semitexa\Core\Request;
use Semitexa\Core\Support\Str;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Hydrates Payload from HTTP Request using setter convention.
 *
 * For each key in raw data (JSON/POST/query + path params), calls set{CamelCase}($value)
 * if the method exists. Value is cast to the setter's parameter type before calling.
 * Path params are keyed by route param name (e.g. 'id' -> setId($value)).
 *
 * Strict mode: when the incoming Request carries $strictHydration === true, type
 * mismatches that cannot be meaningfully coerced throw TypeMismatchException
 * instead of silently casting. The flag is PER-REQUEST (read off the Request,
 * never a process-global static), so it is safe under Swoole concurrency: one
 * request's strictness can never bleed into another's. Only semitexa-testing's
 * InProcessTransport sets it; production requests carry false.
 */
class PayloadHydrator
{
    public static function hydrate(object $dto, Request $httpRequest): object
    {
        $strict = $httpRequest->strictHydration;
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
            $typedValue = self::castValue($value, $type, $key, $strict);
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
     *
     * @return array<string, string>
     */
    private static function extractPathParams(object $dto, Request $httpRequest): array
    {
        $reflection = new ReflectionClass($dto);
        $requestAttrs = $reflection->getAttributes(
            \Semitexa\Core\Attribute\AbstractPayloadRoute::class,
            \ReflectionAttribute::IS_INSTANCEOF,
        );
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

        if (!is_string($routePattern) || $routePattern === '') {
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
            static function (array $m) use ($requirements): string {
                $paramName = $m[1];
                $requirement = $requirements[$paramName] ?? '[^/]+';
                return '(' . (is_string($requirement) ? $requirement : '[^/]+') . ')';
            },
            $regexPattern
        );
        if (!is_string($regexPattern)) {
            return [];
        }
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

    /**
     * @return array<string, mixed>
     */
    public static function getRawData(Request $httpRequest, object $dto): array
    {
        return array_merge(self::collectData($httpRequest), self::extractPathParams($dto, $httpRequest));
    }

    /**
     * @return array<string, mixed>
     */
    private static function collectData(Request $httpRequest): array
    {
        $data = [];

        if ($httpRequest->isJson() && $httpRequest->getContent()) {
            $jsonData = $httpRequest->getJsonBody();
            if ($jsonData !== null) {
                /** @var array<string, mixed> $jsonData */
                $data = array_merge($data, $jsonData);
            } else {
                $content = $httpRequest->getContent();
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    $data = array_merge($data, $decoded);
                }
            }
        } elseif ($httpRequest->isXml() && $httpRequest->getContent()) {
            $data = array_merge($data, self::xmlToArray($httpRequest->getContent()));
        } else {
            /** @var array<string, mixed> $postData */
            $postData = $httpRequest->post;
            $data = array_merge($data, $postData);
        }

        foreach ($httpRequest->query as $key => $value) {
            if (is_string($key) && !array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        // Multipart uploads ride alongside post/json data — the setter dispatch
        // ('setX(UploadedFile $file)') expects the typed object, not raw bytes.
        // A field name collision (file under the same key as a string field) is
        // resolved in favor of the file: uploads only show up in actual
        // multipart requests, where overlap is a clear bug in the client.
        foreach ($httpRequest->files as $field => $upload) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $data[$field] = $upload;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function xmlToArray(string $xml): array
    {
        $element = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($element === false) {
            return [];
        }
        $json = json_encode($element);
        if (!is_string($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function castValue(mixed $value, ?\ReflectionType $type, string $fieldName = '', bool $strict = false): mixed
    {
        if ($type === null) {
            return $value;
        }

        if ($type instanceof ReflectionUnionType) {
            $firstNamed = null;
            foreach ($type->getTypes() as $t) {
                if (!$t instanceof ReflectionNamedType || $t->getName() === 'null') {
                    continue;
                }
                $firstNamed ??= $t->getName();
                // In strict mode, accept the first arm the value cleanly fits so a
                // union like int|string does not reject a valid "hello" just
                // because int happens to be declared first.
                if ($strict && self::isTypeCompatible($value, $t->getName())) {
                    return self::castToType($value, $t->getName(), $fieldName, $strict);
                }
            }
            if ($firstNamed === null) {
                return $value;
            }
            // Non-strict keeps its historical behavior (cast to the first arm).
            // Strict with no compatible arm falls through to castToType, which
            // throws TypeMismatchException for a genuinely incompatible value.
            return self::castToType($value, $firstNamed, $fieldName, $strict);
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            if ($type->allowsNull() && ($value === null || $value === '')) {
                return null;
            }
            return self::castToType($value, $typeName, $fieldName, $strict);
        }

        if ($type instanceof ReflectionIntersectionType) {
            return $value;
        }

        return $value;
    }

    private static function castToType(mixed $value, string $type, string $fieldName = '', bool $strict = false): mixed
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
        if ($strict && !self::isTypeCompatible($value, $type)) {
            throw new TypeMismatchException($fieldName, $type, $value);
        }

        return match ($type) {
            'int' => is_scalar($value) ? (int) $value : 0,
            'float' => is_scalar($value) ? (float) $value : 0.0,
            'bool' => self::castToBool($value),
            'string' => is_scalar($value) ? (string) $value : '',
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
            'int', 'float' => (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value),
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
