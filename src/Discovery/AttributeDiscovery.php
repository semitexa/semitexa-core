<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attributes\AsRequest;
use Semitexa\Core\Attributes\AsRequestHandler;
use Semitexa\Core\Attributes\AsResponseOverride;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\IntelligentAutoloader;
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
    private static array $requestClassAliases = [];
    private static array $rawRequestAttrs = [];
    private static array $resolvedRequestAttrs = [];
    private static array $rawResponseAttrs = [];
    private static array $resolvedResponseAttrs = [];
    private static array $responseClassAliases = [];
    private static array $responseAttrOverrides = [];
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
        
        // Initialize intelligent autoloader first
        IntelligentAutoloader::initialize();
        
        // Preload module Request classes AFTER autoloader is initialized
        // This ensures they're loaded and can be found
        self::preloadModuleRequestClasses();
        
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
     * Find a route by path and method
     * Supports both exact matches and pattern matching with parameters like {type}
     */
    public static function findRoute(string $path, string $method = 'GET'): ?array
    {
        foreach (self::$routes as $route) {
            $routePath = $route['path'];
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
     * Enrich route with handlers and response class
     */
    private static function enrichRoute(array $route): array
    {
        if (($route['type'] ?? null) === 'http-request') {
            $reqClass = $route['class'];
            $extra = self::$httpRequests[$reqClass] ?? null;
            if ($extra) {
                $route['handlers'] = $extra['handlers'];
                $route['responseClass'] = $extra['responseClass'];
            }
        }
        return $route;
    }
    
    /**
     * Scan attributes using intelligent autoloader
     */
    private static function scanAttributesIntelligently(): void
    {
        self::$routes = [];
        self::$httpRequests = [];
        self::$httpHandlers = [];
        self::$requestClassAliases = [];
        self::$rawRequestAttrs = [];
        self::$resolvedRequestAttrs = [];
        self::$rawResponseAttrs = [];
        self::$resolvedResponseAttrs = [];
        self::$responseClassAliases = [];

        // Preload module Handler classes to ensure they're in classMap
        self::preloadModuleHandlerClasses();
        
        // Find all classes with AsRequest attribute
        // Note: preloadModuleRequestClasses is already called in initialize()
        $httpRequestClasses = array_filter(
            IntelligentAutoloader::findClassesWithAttribute(AsRequest::class),
            fn ($class) => str_starts_with($class, 'Semitexa\\') && self::isModuleActiveForClass($class)
        );
        $requestMeta = [];
        $requestGroups = [];
        foreach ($httpRequestClasses as $className) {
            try {
                $class = new ReflectionClass($className);
                $attrs = $class->getAttributes(AsRequest::class);
                if (empty($attrs)) {
                    continue;
                }
                /** @var AsRequest $attr */
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
                    ],
                ];
                $requestMeta[$className] = $meta;
                $groupKey = $meta['attr']['base'] ?? $className;
                $requestGroups[$groupKey][] = $meta;
            } catch (\Throwable $e) {
                // Silently skip on error
            }
        }

        // Process responses before finalizing requests
        self::processResponseAttributes();

        foreach ($requestGroups as $baseClass => $candidates) {
            $projectCandidates = array_values(array_filter($candidates, fn ($c) => self::isProjectRequest($c['file'])));
            // If no project candidates, also include src/modules candidates (for module routes)
            if (empty($projectCandidates)) {
                $projectCandidates = array_values(array_filter($candidates, fn ($c) => str_contains($c['file'], '/src/modules/') || str_contains($c['file'], '/packages/semitexa/')));
            }
            if (empty($projectCandidates)) {
                continue;
            }
            usort($projectCandidates, fn ($a, $b) => $b['priority'] <=> $a['priority']);
            $selected = $projectCandidates[0];

            $resolved = self::resolveRequestAttributes($selected['class'], $requestMeta);

            self::$httpRequests[$selected['class']] = [
                'requestClass' => $selected['class'],
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
                'class' => $selected['class'],
                'method' => '__invoke',
                'requirements' => $resolved['requirements'],
                'defaults' => $resolved['defaults'],
                'options' => $resolved['options'],
                'tags' => $resolved['tags'],
                'public' => $resolved['public'],
                'type' => 'http-request'
            ];


            foreach ($candidates as $candidate) {
                self::$requestClassAliases[$candidate['class']] = $selected['class'];
            }
        }

        // Apply response overrides from src (AsResponseOverride) — only render hints, not class swap
        self::collectResponseOverrides();

        // Find handlers and map to requests (Semitexa packages + project App\ handlers)
        $httpHandlerClasses = array_filter(
            IntelligentAutoloader::findClassesWithAttribute(AsRequestHandler::class),
            fn ($class) => (str_starts_with($class, 'Semitexa\\') && self::isModuleActiveForClass($class))
                || self::isProjectHandler($class)
        );
        foreach ($httpHandlerClasses as $className) {
            try {
                $class = new ReflectionClass($className);
                $attrs = $class->getAttributes(AsRequestHandler::class);
                if (!empty($attrs)) {
                    /** @var AsRequestHandler $attr */
                    $attr = $attrs[0]->newInstance();
                    $for = $attr->for;
                    if (isset(self::$requestClassAliases[$for])) {
                        $for = self::$requestClassAliases[$for];
                    }
                    $execution = HandlerExecution::normalize($attr->execution ?? null);
                    $transport = $attr->transport !== null ? EnvValueResolver::resolve($attr->transport) : null;
                    $queue = $attr->queue !== null ? EnvValueResolver::resolve($attr->queue) : null;
                    $priority = $attr->priority ?? 0;
                    $handlerMeta = [
                        'class' => $class->getName(),
                        'for' => $for,
                        'execution' => $execution->value,
                        'transport' => $transport ?: null,
                        'queue' => $queue ?: null,
                        'priority' => $priority,
                    ];
                    self::$httpHandlers[$class->getName()] = $handlerMeta;
                    if (isset(self::$httpRequests[$for])) {
                        self::$httpRequests[$for]['handlers'][] = $handlerMeta;
                    }
                }
            } catch (\Throwable $e) {
                // Silently skip on error
            }
        }

        // Discover layout slot contributions (optional)
        if (
            class_exists('Semitexa\\Frontend\\Attributes\\AsLayoutSlot')
            && class_exists('Semitexa\\Frontend\\Layout\\LayoutSlotRegistry')
        ) {
            $slotAttribute = 'Semitexa\\Frontend\\Attributes\\AsLayoutSlot';
            $slotClasses = IntelligentAutoloader::findClassesWithAttribute($slotAttribute);
            foreach ($slotClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($slotAttribute);
                    foreach ($attrs as $attr) {
                        /** @var \Semitexa\Frontend\Attributes\AsLayoutSlot $meta */
                        $meta = $attr->newInstance();
                        $handle = $meta->handle;
                        $slot = $meta->slot;
                        $template = EnvValueResolver::resolve($meta->template);
                        $context = EnvValueResolver::resolve($meta->context);
                        $priority = $meta->priority;
                        \Semitexa\Frontend\Layout\LayoutSlotRegistry::register(
                            $handle,
                            $slot,
                            $template,
                            is_array($context) ? $context : [],
                            $priority
                        );
                    }
                } catch (\Throwable $e) {
                    // Silently skip on error
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
        $responseClasses = array_filter(
            IntelligentAutoloader::findClassesWithAttribute(AsResponse::class),
            fn ($class) => str_starts_with($class, 'Semitexa\\')
        );
        if (empty($responseClasses)) {
            return;
        }

        $responseMeta = [];
        $responseGroups = [];
        foreach ($responseClasses as $className) {
            try {
                $class = new ReflectionClass($className);
                $attrs = $class->getAttributes(AsResponse::class);
                if (empty($attrs)) {
                    continue;
                }
                /** @var AsResponse $attr — read by property name only; attribute argument order in source does not matter */
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
            } catch (\Throwable $e) {
                // Silently skip on error
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
        $projectRoot = self::getProjectRoot();
        $projectSrc = $projectRoot . '/src/';

        return str_starts_with($file, $projectSrc);
    }

    private static function isProjectHandler(string $className): bool
    {
        try {
            $file = (new ReflectionClass($className))->getFileName();
            return $file !== false && self::isProjectRequest($file);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Collect response overrides declared with AsResponseOverride.
     * Store class replacement and attribute overrides for later usage.
     */
    private static function collectResponseOverrides(): void
    {
        $overrideClasses = array_filter(
            IntelligentAutoloader::findClassesWithAttribute(AsResponseOverride::class),
            fn ($class) => str_starts_with($class, 'Semitexa\\')
        );
        if (empty($overrideClasses)) {
            return;
        }
        $overrides = [];
        foreach ($overrideClasses as $className) {
            try {
                $rc = new \ReflectionClass($className);
                $file = $rc->getFileName() ?: '';
                $isProjectSrc = (strpos($file, '/src/') !== false) && (strpos($file, '/packages/semitexa/') === false);
                if (!$isProjectSrc) {
                    continue;
                }
                $attrs = $rc->getAttributes(AsResponseOverride::class);
                if (empty($attrs)) {
                    continue;
                }
                /** @var AsResponseOverride $o */
                $o = $attrs[0]->newInstance();
                $overrides[] = ['meta' => $o, 'file' => $file, 'class' => $className];
            } catch (\Throwable $e) {
                // Silently skip on error
            }
        }
        if (empty($overrides)) {
            return;
        }
        usort($overrides, function ($a, $b) {
            return ($b['meta']->priority ?? 0) <=> ($a['meta']->priority ?? 0);
        });
        foreach ($overrides as $ov) {
            /** @var AsResponseOverride $meta */
            $meta = $ov['meta'];
            $target = $meta->of;
            $attrs = [];
            if ($meta->handle !== null) {
                $attrs['handle'] = EnvValueResolver::resolve($meta->handle);
            }
            if ($meta->format !== null) {
                $attrs['format'] = $meta->format; // Enum, no need to resolve
            }
            if ($meta->renderer !== null) {
                $attrs['renderer'] = EnvValueResolver::resolve($meta->renderer);
            }
            if ($meta->context !== null) {
                $attrs['context'] = EnvValueResolver::resolve($meta->context);
            }
            if (!empty($attrs)) {
                $canonical = self::canonicalResponseClass($target);
                $current = self::$resolvedResponseAttrs[$canonical] ?? self::applyResponseDefaults([], self::classBasename($canonical), $canonical);
                foreach ($attrs as $key => $value) {
                    $current[$key] = $value;
                }
                self::$resolvedResponseAttrs[$canonical] = $current;
                self::$responseAttrOverrides[$canonical] = $attrs;
            }
        }
    }
    
    public static function getResponseAttrOverride(string $class): ?array
    {
        return self::$responseAttrOverrides[$class] ?? null;
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
            // All discovery is done via IntelligentAutoloader in scanAttributesIntelligently()
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
    
    /**
     * Preload Request classes from src/modules to ensure they're available for discovery
     */
    private static function preloadModuleRequestClasses(): void
    {
        $projectRoot = self::getProjectRoot();
        $modulesDir = $projectRoot . '/src/modules';
        
        if (!is_dir($modulesDir)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesDir)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                
                // Check if file contains AsRequest attribute and Request in class name
                if (strpos($content, 'AsRequest') !== false && 
                    strpos($content, 'Request') !== false &&
                    preg_match('/namespace\s+([^;]+);/', $content, $nsMatches) &&
                    preg_match('/class\s+(\w+Request)/', $content, $classMatches)) {
                    $fullClassName = $nsMatches[1] . '\\' . $classMatches[1];
                    
                    // Load class if not already loaded
                    if (!class_exists($fullClassName)) {
                        try {
                            // Use IntelligentAutoloader's analyzeFile to add to classMap
                            $reflection = new \ReflectionClass(\Semitexa\Core\IntelligentAutoloader::class);
                            $analyzeMethod = $reflection->getMethod('analyzeFile');
                            $analyzeMethod->setAccessible(true);
                            $analyzeMethod->invoke(null, $file->getPathname());
                            
                            // Then require the file
                            require_once $file->getPathname();
                        } catch (\Throwable $e) {
                            // Skip if can't load
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Get project root directory
     */
    private static function getProjectRoot(): string
    {
        // Try common locations
        $possibleRoots = [
            '/var/www/html',
            getcwd() ?: __DIR__,
        ];
        
        foreach ($possibleRoots as $root) {
            if (is_dir($root . '/src/modules') && is_file($root . '/composer.json')) {
                return $root;
            }
        }
        
        // Fallback: walk up from current directory
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir . '/composer.json') && is_dir($dir . '/src/modules')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        
        return '/var/www/html';
    }
    
    /**
     * Preload Handler classes from src/modules to ensure they're available for discovery
     */
    private static function preloadModuleHandlerClasses(): void
    {
        $projectRoot = self::getProjectRoot();
        $modulesDir = $projectRoot . '/src/modules';
        
        if (!is_dir($modulesDir)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesDir)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                
                // Check if file contains AsRequestHandler attribute and Handler in class name
                if (strpos($content, 'AsRequestHandler') !== false && 
                    strpos($content, 'Handler') !== false &&
                    preg_match('/namespace\s+([^;]+);/', $content, $nsMatches) &&
                    preg_match('/class\s+(\w+Handler)/', $content, $classMatches)) {
                    $fullClassName = $nsMatches[1] . '\\' . $classMatches[1];
                    
                    // Load class if not already loaded
                    if (!class_exists($fullClassName)) {
                        try {
                            // Use IntelligentAutoloader's analyzeFile to add to classMap
                            $reflection = new \ReflectionClass(\Semitexa\Core\IntelligentAutoloader::class);
                            $analyzeMethod = $reflection->getMethod('analyzeFile');
                            $analyzeMethod->setAccessible(true);
                            $analyzeMethod->invoke(null, $file->getPathname());
                            
                            // Then require the file
                            require_once $file->getPathname();
                        } catch (\Throwable $e) {
                            // Skip if can't load
                        }
                    }
                }
            }
        }
    }
}
