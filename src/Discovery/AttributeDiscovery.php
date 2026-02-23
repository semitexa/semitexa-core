<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\AsResource;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Core\Queue\HandlerExecution;
use ReflectionClass;

/**
 * Discovers and caches attributes from PHP classes
 * 
 * This class scans the src/ directory for classes with specific attributes
 * and builds a registry of controllers and routes.
 */
class AttributeDiscovery
{
    private static array $routes = [];
    private static array $httpRequests = [];
    private static array $httpHandlers = [];
    /** @var array<string, list<array{class: string, for?: string, payload?: string, resource?: string, execution: string, transport: ?string, queue: ?string, priority: int}>> key: payload . "\0" . resource */
    private static array $handlersByPayloadAndResource = [];
    private static array $requestClassAliases = [];
    private static array $rawRequestAttrs = [];
    private static array $resolvedRequestAttrs = [];
    private static array $rawResponseAttrs = [];
    private static array $resolvedResponseAttrs = [];
    private static array $responseClassAliases = [];
    private static bool $initialized = false;
    
    /**
     * Initialize the discovery system
     * This should be called once at server startup
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        
        $startTime = microtime(true);
        
        // Initialize class discovery
        ClassDiscovery::initialize();

        // Initialize module registry
        ModuleRegistry::initialize();
        
        // Scan attributes using intelligent autoloader
        self::scanAttributesIntelligently();
        
        $endTime = microtime(true);
        
        self::$initialized = true;
    }
    
    /**
     * Get all discovered routes
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Class names of all handlers discovered via #[AsPayloadHandler].
     * Used by the container to resolve handler instances (handlers are not service contracts).
     *
     * @return list<string>
     */
    public static function getDiscoveredPayloadHandlerClassNames(): array
    {
        self::initialize();
        return array_keys(self::$httpHandlers);
    }

    /**
     * Find a route by path and method
     * Supports both exact matches and pattern matching with parameters like {type}
     */
    public static function findRoute(string $path, string $method = 'GET'): ?array
    {
        // Normalize root path so '' and '/' match the same route
        if ($path === '') {
            $path = '/';
        }
        foreach (self::$routes as $route) {
            $routePath = $route['path'];
            if ($routePath === '') {
                $routePath = '/';
            }
            $routeMethods = $route['methods'] ?? [$route['method'] ?? 'GET'];
            
            // Exact match
            if ($routePath === $path && in_array($method, $routeMethods)) {
                return self::enrichRoute($route);
            }
            
            // Pattern match (e.g., /window-manager/{type}/{file} matches /window-manager/js/window-manager.js)
            if (is_string($routePath) && strpos($routePath, '{') !== false && in_array($method, $routeMethods)) {
                // Build regex pattern: replace {param} with placeholders first, then escape, then replace with regex
                $placeholders = [];
                $tempPath = preg_replace_callback(
                    '/\{([^}]+)\}/',
                    function($m) use (&$placeholders) {
                        $placeholder = '__PLACEHOLDER_' . count($placeholders) . '__';
                        $placeholders[$placeholder] = '([^/]+)';
                        return $placeholder;
                    },
                    $routePath
                );
                
                // Escape the path (use # as delimiter to avoid conflicts with /)
                $pattern = preg_quote($tempPath, '#');
                
                // Replace placeholders with regex groups
                foreach ($placeholders as $placeholder => $regex) {
                    $pattern = str_replace($placeholder, $regex, $pattern);
                }
                
                $pattern = '#^' . $pattern . '$#';
                
                if (preg_match($pattern, $path)) {
                    return self::enrichRoute($route);
                }
            }
        }
        
        return null;
    }

    /**
     * Find a route by its name (e.g. error.404 for custom 404 page).
     */
    public static function findRouteByName(string $name): ?array
    {
        foreach (self::$routes as $route) {
            if (($route['name'] ?? null) === $name) {
                return self::enrichRoute($route);
            }
        }
        return null;
    }
    
    /**
     * Enrich route with handlers and response class.
     * Prefer handlers matched by (payload, resource); fallback to legacy handlers by payload only.
     */
    private static function enrichRoute(array $route): array
    {
        if (($route['type'] ?? null) === 'http-request') {
            $reqClass = $route['class'];
            $extra = self::$httpRequests[$reqClass] ?? null;
            if ($extra) {
                $route['responseClass'] = $extra['responseClass'];
                $route['handlers'] = self::findHandlersByPayloadAndResource($reqClass, $extra['responseClass']);
            }
        }
        return $route;
    }

    /**
     * Ensures every payload referenced by a handler has a registry entry (generated in src/registry/Payloads/).
     * Routes are discovered only from registry; without sync the new payload would not get a route.
     *
     * @throws \RuntimeException when a handler payload has no corresponding App\Registry\Payloads\* class
     */
    private static function assertPayloadsHaveRegistryEntries(): void
    {
        $registryRequestClasses = array_keys(self::$httpRequests);
        $missing = [];
        foreach (self::$handlersByPayloadAndResource as $key => $handlers) {
            $parts = explode("\0", $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $payloadClass = $parts[0];
            $hasRegistry = false;
            foreach ($registryRequestClasses as $requestClass) {
                if ($requestClass === $payloadClass || is_subclass_of($requestClass, $payloadClass)) {
                    $hasRegistry = true;
                    break;
                }
            }
            if (!$hasRegistry) {
                $missing[] = $payloadClass;
            }
        }
        if ($missing !== []) {
            $list = implode(', ', array_unique($missing));
            throw new \RuntimeException(
                "Payload(s) have no registry entry (routes are built from src/registry/Payloads/). Missing: {$list}. Run: bin/semitexa registry:sync:payloads"
            );
        }
    }

    /**
     * Find handlers that match (requestClass, responseClass).
     * Handlers register with module resource class; routes use registry class (subclass of module).
     * Match when: resource === responseClass OR responseClass is subclass of resource.
     *
     * @return list<array{class: string, for?: string, execution: string, transport: ?string, queue: ?string, priority: int, ...}>
     */
    private static function findHandlersByPayloadAndResource(string $requestClass, ?string $responseClass): array
    {
        if ($responseClass === null) {
            return [];
        }
        $found = [];
        foreach (self::$handlersByPayloadAndResource as $key => $handlers) {
            $parts = explode("\0", $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $payload = $parts[0];
            $resource = $parts[1];
            if ($resource !== $responseClass && !is_subclass_of($responseClass, $resource)) {
                continue;
            }
            if ($requestClass !== $payload && !is_subclass_of($requestClass, $payload)) {
                continue;
            }
            foreach ($handlers as $meta) {
                $found[] = $meta;
            }
        }
        return $found;
    }
    
    /**
     * Scan attributes using intelligent autoloader
     */
    private static function scanAttributesIntelligently(): void
    {
        self::$routes = [];
        self::$httpRequests = [];
        self::$httpHandlers = [];
        self::$handlersByPayloadAndResource = [];
        self::$requestClassAliases = [];
        self::$rawRequestAttrs = [];
        self::$resolvedRequestAttrs = [];
        self::$rawResponseAttrs = [];
        self::$resolvedResponseAttrs = [];
        self::$responseClassAliases = [];

        // Single source of truth: only src/registry/Payloads. No route if class is not there.
        $allPayloadClasses = ClassDiscovery::findClassesWithAttribute(AsPayload::class);
        $httpRequestClasses = array_values(array_filter(
            $allPayloadClasses,
            fn ($class) => str_starts_with($class, 'App\\Registry\\Payloads\\')
        ));
        $requestMeta = [];
        $requestGroups = [];
        foreach ($httpRequestClasses as $className) {
            try {
                $class = new ReflectionClass($className);
                $attrs = $class->getAttributes(AsPayload::class);
                if (empty($attrs)) {
                    continue;
                }
                /** @var AsPayload $attr */
                $attr = $attrs[0]->newInstance();
                $meta = [
                    'class' => $className,
                    'short' => $class->getShortName(),
                    'file' => $class->getFileName() ?: '',
                    'priority' => self::determineSourcePriority($class->getFileName() ?: ''),
                    'attr' => [
                        'path' => EnvValueResolver::resolve($attr->path),
                        'methods' => EnvValueResolver::resolve($attr->methods),
                        'name' => $attr->name !== null ? EnvValueResolver::resolve($attr->name) : null,
                        'requirements' => EnvValueResolver::resolve($attr->requirements),
                        'defaults' => EnvValueResolver::resolve($attr->defaults),
                        'options' => EnvValueResolver::resolve($attr->options),
                        'tags' => EnvValueResolver::resolve($attr->tags),
                        'public' => $attr->public,
                        'responseWith' => $attr->responseWith !== null ? EnvValueResolver::resolve($attr->responseWith) : null,
                        'base' => $attr->base ? ltrim($attr->base, '\\') : null,
                        'overrides' => $attr->overrides ? ltrim($attr->overrides, '\\') : null,
                    ],
                ];
                $requestMeta[$className] = $meta;
                $groupKey = $meta['attr']['base'] ?? $className;
                $requestGroups[$groupKey][] = $meta;
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] AttributeDiscovery payload reflection: " . $e->getMessage());
                }
            }
        }

        // Process responses before finalizing requests
        self::processResponseAttributes();

        // Build flat list of all requests with resolved path/methods, then group by route and apply override chain
        $resolvedCache = [];
        $byRoute = [];
        foreach (array_keys($requestMeta) as $className) {
            try {
                $resolved = self::resolveRequestAttributes($className, $requestMeta, $resolvedCache);
                $meta = $requestMeta[$className];
                $overrides = $meta['attr']['overrides'] ?? null;
                $methods = (array) ($resolved['methods'] ?? ['GET']);
                sort($methods);
                $routeKey = $resolved['path'] . "\0" . implode(',', array_map('strtoupper', $methods));
                $byRoute[$routeKey][] = [
                    'class' => $className,
                    'file' => $meta['file'],
                    'priority' => $meta['priority'],
                    'overrides' => $overrides,
                    'resolved' => $resolved,
                ];
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] AttributeDiscovery attribute resolution: " . $e->getMessage());
                }
            }
        }

        foreach ($byRoute as $routeKey => $candidates) {
            $selected = self::selectRequestByOverrideChain($candidates);
            if ($selected === null) {
                continue;
            }
            $resolved = $selected['resolved'];
            $class = $selected['class'];

            self::$httpRequests[$class] = [
                'requestClass' => $class,
                'path' => $resolved['path'],
                'methods' => $resolved['methods'],
                'name' => $resolved['name'],
                'responseClass' => $resolved['responseWith'],
                'file' => $selected['file'],
                'handlers' => [],
            ];

            self::$routes[] = [
                'path' => $resolved['path'],
                'methods' => $resolved['methods'],
                'name' => $resolved['name'],
                'class' => $class,
                'method' => '__invoke',
                'requirements' => $resolved['requirements'],
                'defaults' => $resolved['defaults'],
                'options' => $resolved['options'],
                'tags' => $resolved['tags'],
                'public' => $resolved['public'],
                'type' => 'http-request'
            ];

            foreach ($candidates as $candidate) {
                self::$requestClassAliases[$candidate['class']] = $class;
            }
        }

        // Find handlers and map to requests (Semitexa packages + project App\ handlers)
        $httpHandlerClasses = array_filter(
            ClassDiscovery::findClassesWithAttribute(AsPayloadHandler::class),
            fn ($class) => (str_starts_with($class, 'Semitexa\\') && self::isModuleActiveForClass($class))
                || self::isProjectHandler($class)
        );
        foreach ($httpHandlerClasses as $className) {
            try {
                $class = new ReflectionClass($className);
                $attrs = $class->getAttributes(AsPayloadHandler::class);
                if (!empty($attrs)) {
                    /** @var AsPayloadHandler $attr */
                    $attr = $attrs[0]->newInstance();
                    $payloadClass = $attr->payload;
                    $resourceClass = $attr->resource;
                    $execution = HandlerExecution::normalize($attr->execution ?? null);
                    $transport = $attr->transport !== null ? EnvValueResolver::resolve($attr->transport) : null;
                    $queue = $attr->queue !== null ? EnvValueResolver::resolve($attr->queue) : null;
                    $priority = $attr->priority ?? 0;
                    $handlerMeta = [
                        'class' => $class->getName(),
                        'payload' => $payloadClass,
                        'resource' => $resourceClass,
                        'execution' => $execution->value,
                        'transport' => $transport ?: null,
                        'queue' => $queue ?: null,
                        'priority' => $priority,
                    ];
                    $key = $payloadClass . "\0" . $resourceClass;
                    if (!isset(self::$handlersByPayloadAndResource[$key])) {
                        self::$handlersByPayloadAndResource[$key] = [];
                    }
                    self::$handlersByPayloadAndResource[$key][] = $handlerMeta;
                    self::$httpHandlers[$class->getName()] = $handlerMeta;
                }
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] AttributeDiscovery handler reflection: " . $e->getMessage());
                }
            }
        }

        self::assertPayloadsHaveRegistryEntries();

        // Discover layout slot contributions (optional)
        if (
            class_exists('Semitexa\\Frontend\\Attributes\\AsLayoutSlot')
            && class_exists('Semitexa\\Frontend\\Layout\\LayoutSlotRegistry')
        ) {
            $slotAttribute = 'Semitexa\\Frontend\\Attributes\\AsLayoutSlot';
            $slotClasses = ClassDiscovery::findClassesWithAttribute($slotAttribute);
            foreach ($slotClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($slotAttribute);
                    foreach ($attrs as $attr) {
                        /** @var \Semitexa\Ssr\Attributes\AsLayoutSlot $meta */
                        $meta = $attr->newInstance();
                        $handle = $meta->handle;
                        $slot = $meta->slot;
                        $template = EnvValueResolver::resolve($meta->template);
                        $context = EnvValueResolver::resolve($meta->context);
                        $priority = $meta->priority;
                        \Semitexa\Ssr\Layout\LayoutSlotRegistry::register(
                            $handle,
                            $slot,
                            $template,
                            is_array($context) ? $context : [],
                            $priority
                        );
                    }
                } catch (\Throwable $e) {
                    if (Environment::getEnvValue('APP_DEBUG') === '1') {
                        error_log("[Semitexa] AttributeDiscovery layout slot: " . $e->getMessage());
                    }
                }
            }
        }

    }

    private static function resolveRequestAttributes(string $className, array $metaMap, array &$cache = []): array
    {
        if (isset($cache[$className])) {
            return $cache[$className];
        }
        if (!isset($metaMap[$className])) {
            throw new \RuntimeException("Request metadata missing for {$className}");
        }
        $meta = $metaMap[$className];
        $attr = $meta['attr'];
        if (!empty($attr['base'])) {
            $baseAttr = self::resolveRequestAttributes($attr['base'], $metaMap, $cache);
            $merged = self::mergeRequestAttributes($baseAttr, $attr);
        } else {
            $merged = self::applyRequestDefaults($attr, $meta['short'], $className);
        }
        if (!empty($merged['responseWith'])) {
            $merged['responseWith'] = self::canonicalResponseClass($merged['responseWith']);
        }
        return $cache[$className] = $merged;
    }

    private static function mergeRequestAttributes(array $base, array $override): array
    {
        $result = $base;
        foreach (['path','methods','name','requirements','defaults','options','tags','public','responseWith'] as $key) {
            if ($override[$key] !== null) {
                $result[$key] = $override[$key];
            }
        }
        return $result;
    }

    private static function applyRequestDefaults(array $attr, string $shortName, string $className): array
    {
        if ($attr['path'] === null) {
            throw new \RuntimeException("Request {$className} must define a path");
        }
        return [
            'path' => $attr['path'],
            'methods' => $attr['methods'] ?? ['GET'],
            'name' => $attr['name'] ?? $shortName,
            'requirements' => $attr['requirements'] ?? [],
            'defaults' => $attr['defaults'] ?? [],
            'options' => $attr['options'] ?? [],
            'tags' => $attr['tags'] ?? [],
            'public' => $attr['public'] ?? true,
            'responseWith' => $attr['responseWith'],
        ];
    }

    private static function processResponseAttributes(): void
    {
        // Single source of truth: only src/registry/Resources (same as Payloads from src/registry/Payloads)
        $allResourceClasses = ClassDiscovery::findClassesWithAttribute(AsResource::class);
        $responseClasses = array_values(array_filter(
            $allResourceClasses,
            fn ($class) => str_starts_with($class, 'App\\Registry\\Resources\\')
        ));
        if (empty($responseClasses)) {
            return;
        }

        $responseMeta = [];
        $responseGroups = [];
        foreach ($responseClasses as $className) {
            try {
                $class = new ReflectionClass($className);
                $attrs = $class->getAttributes(AsResource::class);
                if (empty($attrs)) {
                    continue;
                }
                /** @var AsResource $attr — read by property name only; attribute argument order in source does not matter */
                $attr = $attrs[0]->newInstance();
                $meta = [
                    'class' => $className,
                    'short' => $class->getShortName(),
                    'file' => $class->getFileName() ?: '',
                    'priority' => self::determineSourcePriority($class->getFileName() ?: ''),
                    'attr' => [
                        'handle' => $attr->handle !== null ? EnvValueResolver::resolve($attr->handle) : null,
                        'format' => $attr->format,
                        'renderer' => $attr->renderer !== null ? EnvValueResolver::resolve($attr->renderer) : null,
                        'context' => $attr->context ?? [],
                        'base' => $attr->base !== null && $attr->base !== '' ? ltrim($attr->base, '\\') : null,
                    ],
                ];
                $responseMeta[$className] = $meta;
                $groupKey = $meta['attr']['base'] ?? $className;
                $responseGroups[$groupKey][] = $meta;

                // Map registry class to itself and base (module) class to this registry class for canonicalResponseClass()
                self::$responseClassAliases[$className] = $className;
                $parent = $class->getParentClass();
                if ($parent) {
                    self::$responseClassAliases[$parent->getName()] = $className;
                }
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] AttributeDiscovery resource reflection: " . $e->getMessage());
                }
            }
        }

        if (empty($responseMeta)) {
            return;
        }

        self::$rawResponseAttrs = $responseMeta;
        $cache = [];
        foreach ($responseMeta as $className => $meta) {
            self::$resolvedResponseAttrs[$className] = self::resolveResponseAttributes($className, $responseMeta, $cache);
        }

        foreach ($responseGroups as $baseClass => $candidates) {
            usort($candidates, fn ($a, $b) => $b['priority'] <=> $a['priority']);
            $selected = $candidates[0]['class'];
            foreach ($candidates as $candidate) {
                self::$responseClassAliases[$candidate['class']] = $selected;
            }
        }
    }

    private static function resolveResponseAttributes(string $className, array $metaMap, array &$cache = []): array
    {
        if (isset($cache[$className])) {
            return $cache[$className];
        }
        if (!isset($metaMap[$className])) {
            throw new \RuntimeException("Response metadata missing for {$className}");
        }
        $meta = $metaMap[$className];
        $attr = $meta['attr'];
        if (!empty($attr['base'])) {
            $baseAttr = self::resolveResponseAttributes($attr['base'], $metaMap, $cache);
            $merged = self::mergeResponseAttributes($baseAttr, $attr);
        } else {
            $merged = self::applyResponseDefaults($attr, $meta['short'], $className);
        }
        return $cache[$className] = $merged;
    }

    private static function mergeResponseAttributes(array $base, array $override): array
    {
        $result = $base;
        foreach (['handle', 'format', 'renderer', 'context'] as $key) {
            if ($override[$key] !== null && $override[$key] !== []) {
                $result[$key] = $override[$key];
            }
        }
        return $result;
    }

    private static function applyResponseDefaults(array $attr, string $shortName, string $className): array
    {
        $handle = $attr['handle'] ?? self::defaultLayoutHandleFromShortName($shortName);
        return [
            'handle' => $handle,
            'format' => $attr['format'] ?? null,
            'renderer' => $attr['renderer'] ?? null,
            'context' => $attr['context'] ?? [],
        ];
    }

    /**
     * Default layout/template handle from Response class short name.
     * "AboutResponse" -> "about", "HomeResponse" -> "home", so it matches pages/{handle}.html.twig.
     */
    private static function defaultLayoutHandleFromShortName(string $shortName): string
    {
        if (str_ends_with($shortName, 'Response')) {
            $shortName = substr($shortName, 0, -8);
        }
        return strtolower(ltrim(preg_replace('/[A-Z]/', '-$0', $shortName), '-'));
    }

    private static function canonicalResponseClass(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }
        return self::$responseClassAliases[$class] ?? $class;
    }

    public static function getResolvedResponseAttributes(string $class): ?array
    {
        $canonical = self::$responseClassAliases[$class] ?? $class;
        return self::$resolvedResponseAttrs[$canonical] ?? null;
    }

    /**
     * Select the single Request for a route using override chain rules.
     * Only the current chain head can be overridden; otherwise throws.
     *
     * @param list<array{class: string, file: string, priority: int, overrides: ?string, resolved: array}> $candidates
     * @return array{class: string, file: string, priority: int, resolved: array}|null
     */
    private static function selectRequestByOverrideChain(array $candidates): ?array
    {
        if (empty($candidates)) {
            return null;
        }
        usort($candidates, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        $head = null;
        foreach ($candidates as $c) {
            $overrides = $c['overrides'];
            if ($overrides === null || $overrides === '') {
                if ($head !== null) {
                    $head = $c['priority'] > $head['priority'] ? $c : $head;
                } else {
                    $head = $c;
                }
                continue;
            }
            if ($head === null) {
                throw new \RuntimeException(
                    "Request {$c['class']} declares overrides of {$overrides}, but there is no request for this route to override. " .
                    "Remove the overrides attribute (registry is the single source of truth; registry payloads extend module base)."
                );
            }
            $headClass = $head['class'];
            if ($overrides !== $headClass) {
                throw new \RuntimeException(
                    "Request override chain violation: {$c['class']} tries to override {$overrides}, but the current head for this route is {$headClass}. " .
                    "You can only override the current head. Use overrides: {$headClass}::class to extend the chain."
                );
            }
            $head = $c;
        }
        return $head;
    }

    private static function determineSourcePriority(string $file): int
    {
        if ($file === '') {
            return 0;
        }

        if (str_contains($file, '/src/modules/')) {
            return 400;
        }

        if (self::isProjectRequest($file)) {
            return 300;
        }

        if (str_contains($file, '/packages/')) {
            return 200;
        }

        return 100;
    }

    private static function isProjectRequest(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        // Check if file is in project src/ directory (including src/modules/)
        $projectRoot = ProjectRoot::get();
        $projectSrc = $projectRoot . '/src/';

        return str_starts_with($file, $projectSrc);
    }

    private static function isProjectHandler(string $className): bool
    {
        try {
            $file = (new ReflectionClass($className))->getFileName();
            return $file !== false && self::isProjectRequest($file);
        } catch (\Throwable $e) {
            if (Environment::getEnvValue('APP_DEBUG') === '1') {
                error_log("[Semitexa] AttributeDiscovery::isProjectHandler: " . $e->getMessage());
            }
            return false;
        }
    }

    private static function classBasename(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
    
    /**
     * Scan all PHP files in discovered modules (legacy method)
     */
    private static function scanAllAttributes(): void
    {
        $modules = ModuleRegistry::getModules();
        
        foreach ($modules as $module) {
            
            $files = self::getAllPhpFiles($module['path']);
            
            // Legacy scanAllAttributes method is no longer used
            // All discovery is done via ClassDiscovery in scanAttributesIntelligently()
        }
    }
    
    /**
     * Get all PHP files recursively
     */
    private static function getAllPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * Load class from file
     */
    private static function loadClassFromFile(string $file): ?ReflectionClass
    {
        // Skip vendor files
        if (strpos($file, '/vendor/') !== false) {
            return null;
        }
        
        // Extract namespace and class name from file
        $content = file_get_contents($file);
        
        if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            return null;
        }
        
        if (!preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            return null;
        }
        
        $fullClassName = $namespaceMatches[1] . '\\' . $classMatches[1];
        
        // Load the file if class doesn't exist
        if (!class_exists($fullClassName)) {
            require_once $file;
        }
        
        // Check if class exists after loading
        if (!class_exists($fullClassName)) {
            return null;
        }
        
        return new ReflectionClass($fullClassName);
    }

    private static function isModuleActiveForClass(string $className): bool
    {
        $modules = ModuleRegistry::getModules();
        foreach ($modules as $module) {
            if (str_starts_with($className, $module['namespace'])) {
                return ModuleRegistry::isActive($module['name']);
            }
        }
        return true; // Default to active if not recognized as part of a module
    }
    
    
    
}
