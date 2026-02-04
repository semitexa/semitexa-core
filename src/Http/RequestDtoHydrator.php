<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Request;
use ReflectionClass;
use ReflectionProperty;
use Semitexa\Core\Request\Attribute\PathParam;

/**
 * Hydrates Request DTO objects from HTTP Request data
 * 
 * Automatically populates public properties of Request DTOs from:
 * - JSON body (if Content-Type is application/json)
 * - POST data (form-data, x-www-form-urlencoded)
 * - Query parameters
 */
class RequestDtoHydrator
{
    /**
     * Hydrate a Request DTO from HTTP Request
     * 
     * @param object $dto The Request DTO instance to hydrate
     * @param Request $httpRequest The HTTP Request containing data
     * @return object The hydrated DTO
     */
    public static function hydrate(object $dto, Request $httpRequest): object
    {
        $reflection = new ReflectionClass($dto);
        
        // Extract path parameters from route pattern
        $pathParams = self::extractPathParams($dto, $httpRequest);
        
        // Collect data from all sources
        $data = self::collectData($httpRequest);
        
        // Merge path parameters (highest priority)
        $data = array_merge($data, $pathParams);
        
        // Hydrate each public property
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            
            // Get value from collected data
            $value = $data[$propertyName] ?? null;
            
            // Check if property has PathParam attribute (required parameter)
            $pathParamAttrs = $property->getAttributes(PathParam::class);
            $isPathParam = !empty($pathParamAttrs);
            
            // Set value if available
            if ($value !== null) {
                // Get property type for type casting
                $type = $property->getType();
                $typedValue = self::castValue($value, $type);
                
                // Set property value
                $property->setValue($dto, $typedValue);
            } elseif ($isPathParam && !$property->isInitialized($dto)) {
                // Path parameter is required but not found - set empty string for string types
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                    $property->setValue($dto, '');
                }
            }
        }
        
        return $dto;
    }
    
    /**
     * Extract path parameters from URL based on route pattern
     */
    private static function extractPathParams(object $dto, Request $httpRequest): array
    {
        $params = [];
        $reflection = new ReflectionClass($dto);
        
        // Get route pattern from AsRequest attribute
        $requestAttrs = $reflection->getAttributes(\Semitexa\Core\Attributes\AsRequest::class);
        if (empty($requestAttrs)) {
            return $params;
        }
        
        try {
            $requestAttr = $requestAttrs[0]->newInstance();
            $routePattern = $requestAttr->path ?? null;
        } catch (\Throwable $e) {
            error_log("RequestDtoHydrator: Failed to get AsRequest attribute: " . $e->getMessage());
            return $params;
        }
        
        // Handle resolved path (might be false if EnvValueResolver returned false for null)
        if ($routePattern === null || $routePattern === false) {
            return $params;
        }
        
        // Ensure it's a string
        if (!is_string($routePattern)) {
            error_log("RequestDtoHydrator: routePattern is not a string: " . gettype($routePattern) . " = " . var_export($routePattern, true));
            return $params;
        }
        
        if (empty($routePattern)) {
            return $params;
        }
        
        $needle = '{';
        if (!is_string($needle)) {
            error_log("RequestDtoHydrator: needle is not a string: " . gettype($needle));
            return $params;
        }
        if (strpos($routePattern, $needle) === false) {
            return $params; // No path parameters
        }
        
        // Extract all path parameters from route pattern
        $pathParams = [];
        if (preg_match_all('/\{([^}]+)\}/', $routePattern, $paramMatches)) {
            foreach ($paramMatches[1] as $index => $paramName) {
                $pathParams[$paramName] = $index;
            }
        }
        
        // Build regex pattern to match the route (use # as delimiter to avoid conflicts with /)
        $regexPattern = preg_quote($routePattern, '#');
        $regexPattern = preg_replace('#\\\{([^}]+)\\\}#', '([^/]+)', $regexPattern);
        $regexPattern = '#^' . $regexPattern . '$#';
        
        // Match actual path against pattern
        if (preg_match($regexPattern, $httpRequest->getPath(), $matches)) {
            // Map captured groups to parameter names
            foreach ($pathParams as $paramName => $groupIndex) {
                $captureIndex = $groupIndex + 1; // First match is full string
                if (isset($matches[$captureIndex])) {
                    $params[$paramName] = $matches[$captureIndex];
                }
            }
        }
        
        // Map to property names using PathParam attributes
        $mappedParams = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $pathParamAttrs = $property->getAttributes(PathParam::class);
            if (empty($pathParamAttrs)) {
                continue;
            }
            
            $pathParamAttr = $pathParamAttrs[0]->newInstance();
            $paramName = $pathParamAttr->name ?? $property->getName();
            
            if (isset($params[$paramName])) {
                $mappedParams[$property->getName()] = $params[$paramName];
            }
        }
        
        return $mappedParams;
    }
    
    /**
     * Collect data from HTTP Request (JSON body, POST, query)
     * 
     * Priority order:
     * 1. JSON body (if Content-Type: application/json) - highest priority
     * 2. POST data (form-urlencoded, multipart/form-data) - parsed by Swoole
     * 3. Query parameters - lowest priority
     */
    private static function collectData(Request $httpRequest): array
    {
        $data = [];
        
        // 1. Parse JSON body if Content-Type is application/json (highest priority)
        // Swoole doesn't populate ->post for JSON requests, so we parse from raw content
        if ($httpRequest->isJson() && $httpRequest->getContent()) {
            $jsonData = $httpRequest->getJsonBody();
            if ($jsonData !== null) {
                $data = array_merge($data, $jsonData);
            } else {
                // Fallback: try to parse content directly if getJsonBody() failed
                $content = $httpRequest->getContent();
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = array_merge($data, $decoded);
                }
            }
        } else {
            // 2. Add POST data (form-urlencoded, multipart/form-data)
            // Swoole automatically parses these into ->post array
            $data = array_merge($data, $httpRequest->post);
        }
        
        // 3. Add query parameters (lowest priority - only if not already set)
        foreach ($httpRequest->query as $key => $value) {
            if (!isset($data[$key])) {
                $data[$key] = $value;
            }
        }
        
        return $data;
    }
    
    /**
     * Cast value to appropriate type based on property type
     */
    private static function castValue(mixed $value, ?\ReflectionType $type): mixed
    {
        if ($type === null) {
            return $value;
        }
        
        // Handle union types (e.g., int|string|null)
        if ($type instanceof \ReflectionUnionType) {
            $types = $type->getTypes();
            // Try to cast to first non-null type
            foreach ($types as $t) {
                if ($t->getName() !== 'null') {
                    return self::castToType($value, $t->getName());
                }
            }
            return $value;
        }
        
        // Handle nullable types (e.g., ?int)
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();
            
            // Handle nullable
            if ($type->allowsNull() && ($value === null || $value === '')) {
                return null;
            }
            
            return self::castToType($value, $typeName);
        }
        
        return $value;
    }
    
    /**
     * Cast value to specific type
     */
    private static function castToType(mixed $value, string $type): mixed
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
     * Cast value to boolean
     */
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

