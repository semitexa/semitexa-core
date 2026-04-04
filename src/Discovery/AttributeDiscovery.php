<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\AsPayloadPart;
use Semitexa\Core\Attributes\AsResource;
use Semitexa\Core\Attributes\AsResourcePart;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Pipeline\HandlerReflectionCache;
use Semitexa\Core\Support\TenantModuleScopeResolver;
use ReflectionClass;

/**
 * Discovers and caches attributes from PHP classes
 * 
 * This class scans the src/ directory for classes with specific attributes
 * and builds a registry of controllers and routes.
 */
class AttributeDiscovery
{
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
    /** @var array<string, list<string>> baseClass => [traitFQN, ...] */
    private static array $payloadParts = [];
    /** @var array<string, list<string>> baseClass => [traitFQN, ...] */
    private static array $resourceParts = [];
    /** @var array<string, string> className => attribute base class */
    private static array $payloadBaseMap = [];
    /** @var array<string, string> className => attribute base class */
    private static array $resourceBaseMap = [];
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

        // Discovery relies on tenant/module env such as TENANT_*_MODULES.
        // CLI entrypoints can reach discovery before worker bootstrap syncs .env values.
        Environment::syncEnvFromFiles();
        
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
        return RouteRegistry::getAll();
    }

    /**
     * Get all discovered routes with responseClass and handlers populated.
     *
     * @return list<array>
     */
    public static function getEnrichedRoutes(): array
    {
        return array_map(static fn(array $route) => self::enrichRoute($route), RouteRegistry::getAll());
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
     * Find a route by path and method.
     * Delegates to RouteRegistry for indexed lookup, then enriches with handler/response data.
     */
    public static function findRoute(string $path, string $method = 'GET'): ?array
    {
        $route = RouteRegistry::find($path, $method);
        if ($route === null) {
            return null;
        }
        return self::enrichRoute($route);
    }

    /**
     * Find a route by its name (e.g. error.404 for custom 404 page).
     */
    public static function findRouteByName(string $name): ?array
    {
        $route = RouteRegistry::findByName($name);
        if ($route === null) {
            return null;
        }
        return self::enrichRoute($route);
    }
    
    /**
     * Enrich route with handlers and response class.
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
     * Ensures every payload referenced by a handler has a corresponding discovered route.
     *
     * @throws \RuntimeException when a handler payload has no discovered route
     */
    private static function assertPayloadsHaveDiscoveredRoutes(): void
    {
        $discoveredPayloadClasses = array_keys(self::$httpRequests);
        $missing = [];
        foreach (self::$handlersByPayloadAndResource as $key => $handlers) {
            $parts = explode("\0", $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $payloadClass = $parts[0];
            $hasRoute = false;
            foreach ($discoveredPayloadClasses as $requestClass) {
                if ($requestClass === $payloadClass || is_subclass_of($requestClass, $payloadClass)) {
                    $hasRoute = true;
                    break;
                }
            }
            if (!$hasRoute) {
                $missing[] = $payloadClass;
            }
        }
        if ($missing !== []) {
            $list = implode(', ', array_unique($missing));
            throw new \RuntimeException(
                "Payload(s) referenced by handlers have no discovered route. Missing: {$list}. " .
                "Ensure the payload class has #[AsPayload] and belongs to an active module or project src/."
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
     * Scan attributes using intelligent autoloader.
     * Orchestrates discovery in named phases for readability.
     */
    private static function scanAttributesIntelligently(): void
    {
        $diagnostics = BootDiagnostics::current();

        self::resetState();
        self::discoverPayloadsAndRoutes($diagnostics);
        self::discoverHandlers($diagnostics);
        self::discoverParts($diagnostics);
        self::discoverSsrComponents($diagnostics);
    }

    private static function resetState(): void
    {
        RouteRegistry::reset();
        self::$httpRequests = [];
        self::$httpHandlers = [];
        self::$handlersByPayloadAndResource = [];
        self::$requestClassAliases = [];
        self::$rawRequestAttrs = [];
        self::$resolvedRequestAttrs = [];
        self::$rawResponseAttrs = [];
        self::$resolvedResponseAttrs = [];
        self::$responseClassAliases = [];
        self::$payloadParts = [];
        self::$resourceParts = [];
        self::$payloadBaseMap = [];
        self::$resourceBaseMap = [];
    }

    private static function discoverPayloadsAndRoutes(BootDiagnostics $diagnostics): void
    {
        // Runtime discovery: accept payloads from active modules and project src/
        $allPayloadClasses = ClassDiscovery::findClassesWithAttribute(AsPayload::class);
        $httpRequestClasses = array_values(array_filter(
            $allPayloadClasses,
            fn ($class) => ModuleRegistry::isClassActive($class) || self::isProjectPayload($class)
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
                        'consumes' => $attr->consumes,
                        'produces' => $attr->produces,
                    ],
                ];
                $requestMeta[$className] = $meta;
                if ($meta['attr']['base'] !== null) {
                    self::$payloadBaseMap[$className] = $meta['attr']['base'];
                }
                $groupKey = $meta['attr']['base'] ?? $className;
                $requestGroups[$groupKey][] = $meta;
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Payload reflection failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        // Process responses before finalizing requests
        self::processResponseAttributes($diagnostics);

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
                $moduleName = ModuleRegistry::getModuleNameForClass($className) ?? 'project';
                $scopeSignature = TenantModuleScopeResolver::scopeSignatureForModule($moduleName);
                $byRoute[$routeKey . "\0" . $scopeSignature][] = [
                    'class' => $className,
                    'file' => $meta['file'],
                    'priority' => $meta['priority'],
                    'overrides' => $overrides,
                    'resolved' => $resolved,
                    'module' => $moduleName,
                    'tenantScopes' => TenantModuleScopeResolver::scopesForModule($moduleName),
                ];
            } catch (\Throwable $e) {
                $diagnostics->invalidUsage('AttributeDiscovery', "Attribute resolution failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        foreach ($byRoute as $routeKey => $candidates) {
            $selected = self::selectRequestByOverrideChain($candidates);
            if ($selected === null) {
                continue;
            }
            $selectedModule = $selected['module'];
            $selectedTenantScopes = $selected['tenantScopes'];
            $resolved = $selected['resolved'];
            $class = $selected['class'];

            self::$httpRequests[$class] = [
                'requestClass' => $class,
                'path' => $resolved['path'],
                'methods' => $resolved['methods'],
                'name' => $resolved['name'],
                'responseClass' => $resolved['responseWith'],
                'file' => $selected['file'],
                'module' => $selectedModule,
                'tenantScopes' => $selectedTenantScopes,
                'handlers' => [],
            ];

            // Resolve produces: payload-level takes precedence over response class AsResource
            $routeProduces = $resolved['produces'] ?? null;
            if ($routeProduces === null) {
                $responseClass = $resolved['responseWith'] ?? null;
                if ($responseClass !== null) {
                    $resolvedResp = self::getResolvedResponseAttributes($responseClass);
                    if ($resolvedResp !== null && isset($resolvedResp['produces'])) {
                        $routeProduces = $resolvedResp['produces'];
                    }
                }
            }

            // Framework-reserved path validation
            $reservedPaths = ['/__semitexa_kiss', '/__semitexa_hug', '/__semitexa_sse'];
            $normalizedPath = rtrim($resolved['path'], '/');
            if (in_array($normalizedPath, $reservedPaths, true)
                && !str_starts_with($class, 'Semitexa\\Ssr\\')
                && !str_starts_with($class, 'Semitexa\\Core\\')
            ) {
                throw new \Semitexa\Core\Exception\ConflictException(
                    "Route path '{$resolved['path']}' is reserved by the Semitexa framework and cannot be claimed by non-framework class {$class}."
                );
            }

            RouteRegistry::register([
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
                'type' => 'http-request',
                'consumes' => $resolved['consumes'] ?? null,
                'produces' => $routeProduces,
                'module' => $selectedModule,
                'tenantScopes' => $selectedTenantScopes,
            ]);

            foreach ($candidates as $candidate) {
                self::$requestClassAliases[$candidate['class']] = $class;
            }
        }
    }

    private static function discoverHandlers(BootDiagnostics $diagnostics): void
    {
        // Find handlers and map to requests (Semitexa packages + project App\ handlers)
        $httpHandlerClasses = array_filter(
            ClassDiscovery::findClassesWithAttribute(AsPayloadHandler::class),
            fn ($class) => (
                (str_starts_with($class, 'Semitexa\\') || str_starts_with($class, 'App\\Modules\\'))
                && ModuleRegistry::isClassActive($class)
            ) || (
                self::isProjectHandler($class)
                && !str_starts_with($class, 'App\\Modules\\')
            )
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
                        'maxRetries' => $attr->maxRetries,
                        'retryDelay' => $attr->retryDelay,
                    ];
                    $key = $payloadClass . "\0" . $resourceClass;
                    if (!isset(self::$handlersByPayloadAndResource[$key])) {
                        self::$handlersByPayloadAndResource[$key] = [];
                    }
                    self::$handlersByPayloadAndResource[$key][] = $handlerMeta;
                    self::$httpHandlers[$class->getName()] = $handlerMeta;

                    // Warm reflection cache for TypedHandlerInterface handlers
                    if ($class->implementsInterface(TypedHandlerInterface::class)) {
                        try {
                            HandlerReflectionCache::warm($class->getName());
                        } catch (\LogicException $e) {
                            throw new \LogicException(
                                "Failed to warm reflection cache for TypedHandlerInterface handler {$class->getName()}: " . $e->getMessage(),
                                0,
                                $e
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Handler reflection failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        self::assertPayloadsHaveDiscoveredRoutes();
    }

    private static function discoverParts(BootDiagnostics $diagnostics): void
    {
        self::discoverPayloadParts($diagnostics);
        self::discoverResourceParts($diagnostics);
    }

    private static function discoverSsrComponents(BootDiagnostics $diagnostics): void
    {
        // Discover layout slot contributions (optional)
        if (
            class_exists('Semitexa\\Ssr\\Attributes\\AsLayoutSlot')
            && class_exists('Semitexa\\Ssr\\Layout\\LayoutSlotRegistry')
        ) {
            $slotAttribute = 'Semitexa\\Ssr\\Attributes\\AsLayoutSlot';
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
                            $priority,
                            $meta->deferred ?? false,
                            $meta->cacheTtl ?? 0,
                            $meta->dataProvider ?? null,
                            $meta->skeletonTemplate ?? null,
                            $meta->mode ?? 'html',
                            $meta->refreshInterval ?? 0,
                        );
                    }
                } catch (\Throwable $e) {
                    $diagnostics->skip('AttributeDiscovery', "Layout slot failed for {$className}: " . $e->getMessage(), $e);
                }
            }
        }

        // Discover DataProvider registrations (optional)
        if (
            class_exists('Semitexa\\Ssr\\Attributes\\AsDataProvider')
            && class_exists('Semitexa\\Ssr\\Application\\Service\\DataProviderRegistry')
        ) {
            $dpAttribute = 'Semitexa\\Ssr\\Attributes\\AsDataProvider';
            $dpClasses = array_values(array_filter(
                ClassDiscovery::findClassesWithAttribute($dpAttribute),
                fn (string $class) => ModuleRegistry::isClassActive($class) || self::isProjectResource($class)
            ));
            foreach ($dpClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($dpAttribute);
                    foreach ($attrs as $attr) {
                        $meta = $attr->newInstance();
                        if (!property_exists($meta, 'slot') || $meta->slot === null || $meta->slot === '') {
                            throw new \RuntimeException("AsDataProvider on {$className} is missing slot.");
                        }
                        $handles = property_exists($meta, 'handles') && is_array($meta->handles) ? $meta->handles : [];
                        \Semitexa\Ssr\Application\Service\DataProviderRegistry::register(
                            $meta->slot,
                            $className,
                            $handles,
                        );
                    }
                } catch (\Throwable $e) {
                    $diagnostics->skip('AttributeDiscovery', "Data provider failed for {$className}: " . $e->getMessage(), $e);
                }
            }
        }

        // Discover AsSlotResource contributions (optional)
        if (
            class_exists('Semitexa\\Ssr\\Attributes\\AsSlotResource')
            && class_exists('Semitexa\\Ssr\\Layout\\LayoutSlotRegistry')
        ) {
            $slotResourceAttribute = 'Semitexa\\Ssr\\Attributes\\AsSlotResource';
            $slotResourceClasses = array_values(array_filter(
                ClassDiscovery::findClassesWithAttribute($slotResourceAttribute),
                fn (string $class) => ModuleRegistry::isClassActive($class) || self::isProjectResource($class)
            ));
            foreach ($slotResourceClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($slotResourceAttribute);
                    foreach ($attrs as $attr) {
                        /** @var \Semitexa\Ssr\Attributes\AsSlotResource $meta */
                        $meta = $attr->newInstance();
                        $template = EnvValueResolver::resolve($meta->template);
                        $context = EnvValueResolver::resolve($meta->context);
                        \Semitexa\Ssr\Layout\LayoutSlotRegistry::register(
                            handle: $meta->handle,
                            slot: $meta->slot,
                            template: is_string($template) ? $template : $meta->template,
                            context: is_array($context) ? $context : [],
                            priority: $meta->priority,
                            deferred: $meta->deferred,
                            cacheTtl: $meta->cacheTtl,
                            dataProvider: null,
                            skeletonTemplate: $meta->skeletonTemplate,
                            mode: $meta->mode,
                            refreshInterval: $meta->refreshInterval,
                            resourceClass: $className,
                            clientModules: $meta->clientModules,
                        );
                    }
                } catch (\Throwable $e) {
                    $diagnostics->skip('AttributeDiscovery', "Slot resource failed for {$className}: " . $e->getMessage(), $e);
                }
            }
        }

        // Discover AsSlotHandler contributions (optional)
        if (
            class_exists('Semitexa\\Ssr\\Attributes\\AsSlotHandler')
            && class_exists('Semitexa\\Ssr\\Layout\\SlotHandlerRegistry')
        ) {
            $slotHandlerAttribute = 'Semitexa\\Ssr\\Attributes\\AsSlotHandler';
            $slotHandlerClasses = array_values(array_filter(
                ClassDiscovery::findClassesWithAttribute($slotHandlerAttribute),
                fn (string $class) => ModuleRegistry::isClassActive($class) || self::isProjectResource($class)
            ));
            foreach ($slotHandlerClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($slotHandlerAttribute);
                    foreach ($attrs as $attr) {
                        /** @var \Semitexa\Ssr\Attributes\AsSlotHandler $meta */
                        $meta = $attr->newInstance();
                        \Semitexa\Ssr\Layout\SlotHandlerRegistry::register(
                            slotClass: $meta->slot,
                            handlerClass: $className,
                            priority: $meta->priority,
                        );
                    }
                } catch (\Throwable $e) {
                    $diagnostics->skip('AttributeDiscovery', "Slot handler failed for {$className}: " . $e->getMessage(), $e);
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
        foreach (['path','methods','name','requirements','defaults','options','tags','public','responseWith','consumes','produces'] as $key) {
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
            'consumes' => $attr['consumes'] ?? null,
            'produces' => $attr['produces'] ?? null,
        ];
    }

    private static function processResponseAttributes(BootDiagnostics $diagnostics): void
    {
        // Runtime discovery: accept resources from active modules and project src/
        $allResourceClasses = ClassDiscovery::findClassesWithAttribute(AsResource::class);
        $responseClasses = array_values(array_filter(
            $allResourceClasses,
            fn ($class) => ModuleRegistry::isClassActive($class) || self::isProjectResource($class)
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
                        'template' => $attr->template !== null ? EnvValueResolver::resolve($attr->template) : null,
                        'context' => $attr->context ?? [],
                        'base' => $attr->base !== null && $attr->base !== '' ? ltrim($attr->base, '\\') : null,
                        'produces' => $attr->produces,
                    ],
                ];
                $responseMeta[$className] = $meta;
                if ($meta['attr']['base'] !== null) {
                    self::$resourceBaseMap[$className] = $meta['attr']['base'];
                }
                $groupKey = $meta['attr']['base'] ?? $className;
                $responseGroups[$groupKey][] = $meta;

                self::$responseClassAliases[$className] = $className;
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Resource reflection failed for {$className}: " . $e->getMessage(), $e);
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
        foreach (['handle', 'format', 'renderer', 'template', 'context', 'produces'] as $key) {
            if (!\array_key_exists($key, $override)) {
                continue;
            }
            if ($override[$key] !== null) {
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
            'template' => $attr['template'] ?? null,
            'context' => \array_key_exists('context', $attr) ? $attr['context'] : null,
            'produces' => $attr['produces'] ?? null,
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
     * @param list<array{
     *   class: string,
     *   file: string,
     *   priority: int,
     *   overrides: ?string,
     *   resolved: array,
     *   module: string,
     *   tenantScopes: list<string>
     * }> $candidates
     * @return array{
     *   class: string,
     *   file: string,
     *   priority: int,
     *   overrides?: ?string,
     *   resolved: array,
     *   module: string,
     *   tenantScopes: list<string>
     * }|null
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
            BootDiagnostics::current()->skip('AttributeDiscovery', "isProjectHandler check failed for {$className}: " . $e->getMessage(), $e);
            return false;
        }
    }

    private static function isProjectPayload(string $className): bool
    {
        try {
            $file = (new ReflectionClass($className))->getFileName();
            return $file !== false && self::isProjectRequest($file);
        } catch (\Throwable $e) {
            BootDiagnostics::current()->skip('AttributeDiscovery', "isProjectPayload check failed for {$className}: " . $e->getMessage(), $e);
            return false;
        }
    }

    private static function isProjectResource(string $className): bool
    {
        try {
            $file = (new ReflectionClass($className))->getFileName();
            return $file !== false && self::isProjectRequest($file);
        } catch (\Throwable $e) {
            BootDiagnostics::current()->skip('AttributeDiscovery', "isProjectResource check failed for {$className}: " . $e->getMessage(), $e);
            return false;
        }
    }

    private static function classBasename(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
    
    /**
     * Discover traits marked with #[AsPayloadPart] from active modules.
     */
    private static function discoverPayloadParts(BootDiagnostics $diagnostics): void
    {
        $classes = ClassDiscovery::findClassesWithAttribute(AsPayloadPart::class);
        foreach ($classes as $className) {
            if (!ModuleRegistry::isClassActive($className) && !self::isProjectPayload($className)) {
                continue;
            }
            try {
                $ref = new ReflectionClass($className);
                if (!$ref->isTrait()) {
                    continue;
                }
                $attrs = $ref->getAttributes(AsPayloadPart::class);
                foreach ($attrs as $attr) {
                    $instance = $attr->newInstance();
                    $base = ltrim($instance->base, '\\');
                    self::$payloadParts[$base][] = $className;
                }
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Payload part failed for {$className}: " . $e->getMessage(), $e);
            }
        }
    }

    private static function discoverResourceParts(BootDiagnostics $diagnostics): void
    {
        $classes = ClassDiscovery::findClassesWithAttribute(AsResourcePart::class);
        foreach ($classes as $className) {
            if (!ModuleRegistry::isClassActive($className) && !self::isProjectResource($className)) {
                continue;
            }
            try {
                $ref = new ReflectionClass($className);
                if (!$ref->isTrait()) {
                    continue;
                }
                $attrs = $ref->getAttributes(AsResourcePart::class);
                foreach ($attrs as $attr) {
                    $instance = $attr->newInstance();
                    $base = ltrim($instance->base, '\\');
                    self::$resourceParts[$base][] = $className;
                }
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Resource part failed for {$className}: " . $e->getMessage(), $e);
            }
        }
    }

    /**
     * Get trait list for a payload class.
     * Matches via PHP inheritance and attribute base chain.
     *
     * @return list<string>
     */
    public static function getPayloadPartsForClass(string $requestClass): array
    {
        self::initialize();
        $chain = self::buildBaseChain($requestClass, self::$payloadBaseMap);
        $traits = [];
        foreach (self::$payloadParts as $base => $traitList) {
            if (in_array($base, $chain, true) || is_subclass_of($requestClass, $base)) {
                array_push($traits, ...$traitList);
            }
        }
        return $traits;
    }

    /**
     * Get trait list for a resource class.
     * Matches via PHP inheritance and attribute base chain.
     *
     * @return list<string>
     */
    public static function getResourcePartsForClass(string $responseClass): array
    {
        self::initialize();
        $chain = self::buildBaseChain($responseClass, self::$resourceBaseMap);
        $traits = [];
        foreach (self::$resourceParts as $base => $traitList) {
            if (in_array($base, $chain, true) || is_subclass_of($responseClass, $base)) {
                array_push($traits, ...$traitList);
            }
        }
        return $traits;
    }

    /**
     * Walk the attribute base chain for a class.
     *
     * @return list<string> The class itself and all ancestors via attribute base
     */
    private static function buildBaseChain(string $className, array $baseMap): array
    {
        $chain = [];
        $current = $className;
        while ($current !== null) {
            $chain[] = $current;
            $current = $baseMap[$current] ?? null;
        }
        return $chain;
    }

}
