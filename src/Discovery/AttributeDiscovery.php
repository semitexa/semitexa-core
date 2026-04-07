<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsPayloadPart;
use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Attribute\AsResourcePart;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Pipeline\HandlerReflectionCache;
use Semitexa\Core\Support\TenantModuleScopeResolver;
use ReflectionClass;
use Semitexa\Core\Exception\ConfigurationException;

/**
 * Discovers and caches attributes from PHP classes
 *
 * This class scans the src/ directory for classes with specific attributes
 * and builds a registry of controllers and routes.
 */
class AttributeDiscovery
{
    private array $httpRequests = [];
    private array $httpHandlers = [];
    /** @var array<string, list<array{class: string, for?: string, payload?: string, resource?: string, execution: string, transport: ?string, queue: ?string, priority: int}>> key: payload . "\0" . resource */
    private array $handlersByPayloadAndResource = [];
    private array $resolvedResponseAttrs = [];
    private array $responseClassAliases = [];
    /** @var array<string, list<string>> baseClass => [traitFQN, ...] */
    private array $payloadParts = [];
    /** @var array<string, list<string>> baseClass => [traitFQN, ...] */
    private array $resourceParts = [];
    /** @var array<string, string> className => attribute base class */
    private array $payloadBaseMap = [];
    /** @var array<string, string> className => attribute base class */
    private array $resourceBaseMap = [];
    private bool $initialized = false;

    private readonly HandlerRegistry $handlerRegistry;
    private readonly PayloadPartRegistry $payloadPartRegistry;

    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly RouteRegistry $routeRegistry,
        ?HandlerRegistry $handlerRegistry = null,
        ?PayloadPartRegistry $payloadPartRegistry = null,
    ) {
        $this->handlerRegistry = $handlerRegistry ?? new HandlerRegistry();
        $this->payloadPartRegistry = $payloadPartRegistry ?? new PayloadPartRegistry();
    }

    /**
     * Get the handler registry populated during discovery.
     */
    public function getHandlerRegistry(): HandlerRegistry
    {
        return $this->handlerRegistry;
    }

    /**
     * Get the payload part registry populated during discovery.
     */
    public function getPayloadPartRegistry(): PayloadPartRegistry
    {
        return $this->payloadPartRegistry;
    }

    /**
     * Initialize the discovery system
     * This should be called once at server startup
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Discovery relies on tenant/module env such as TENANT_*_MODULES.
        // CLI entrypoints can reach discovery before worker bootstrap syncs .env values.
        Environment::syncEnvFromFiles();

        // Initialize class discovery
        $this->classDiscovery->initialize();

        // Initialize module registry
        $this->moduleRegistry->initialize();

        // Scan attributes using intelligent autoloader
        $this->scanAttributesIntelligently();

        $this->initialized = true;
    }

    /**
     * Get all discovered routes
     */
    public function getRoutes(): array
    {
        return $this->routeRegistry->getAll();
    }

    /**
     * Get all discovered routes with responseClass and handlers populated.
     *
     * @return list<array>
     */
    public function getEnrichedRoutes(): array
    {
        return array_map(fn(array $route) => $this->enrichRoute($route), $this->routeRegistry->getAll());
    }

    /**
     * Class names of all handlers discovered via #[AsPayloadHandler].
     * Used by the container to resolve handler instances (handlers are not service contracts).
     *
     * @return list<string>
     */
    public function getDiscoveredPayloadHandlerClassNames(): array
    {
        $this->initialize();
        return array_keys($this->httpHandlers);
    }

    /**
     * Find a route by path and method.
     * Delegates to RouteRegistry for indexed lookup, then enriches with handler/response data.
     */
    public function findRoute(string $path, string $method = 'GET'): ?array
    {
        $route = $this->routeRegistry->find($path, $method);
        if ($route === null) {
            return null;
        }
        return $this->enrichRoute($route);
    }

    /**
     * Find a route by its name (e.g. error.404 for custom 404 page).
     */
    public function findRouteByName(string $name): ?array
    {
        $route = $this->routeRegistry->findByName($name);
        if ($route === null) {
            return null;
        }
        return $this->enrichRoute($route);
    }

    /**
     * Enrich route with handlers and response class.
     */
    private function enrichRoute(array $route): array
    {
        if (($route['type'] ?? null) === 'http-request') {
            $reqClass = is_string($route['class'] ?? null) ? $route['class'] : null;
            if ($reqClass === null) {
                return $route;
            }
            $extra = $this->httpRequests[$reqClass] ?? null;
            if ($extra) {
                $route['responseClass'] = $extra['responseClass'];
                $responseClass = is_string($extra['responseClass'] ?? null) ? $extra['responseClass'] : null;
                $route['handlers'] = $this->findHandlersByPayloadAndResource($reqClass, $responseClass);
            }
        }
        return $route;
    }

    /**
     * Ensures every payload referenced by a handler has a corresponding discovered route.
     *
     * @throws \RuntimeException when a handler payload has no discovered route
     */
    private function assertPayloadsHaveDiscoveredRoutes(): void
    {
        $discoveredPayloadClasses = array_keys($this->httpRequests);
        $missing = [];
        foreach ($this->handlersByPayloadAndResource as $key => $handlers) {
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
            throw new ConfigurationException(
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
    private function findHandlersByPayloadAndResource(string $requestClass, ?string $responseClass): array
    {
        if ($responseClass === null) {
            return [];
        }
        $found = [];
        foreach ($this->handlersByPayloadAndResource as $key => $handlers) {
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
    private function scanAttributesIntelligently(): void
    {
        $diagnostics = BootDiagnostics::current();

        $this->resetState();
        $this->discoverPayloadsAndRoutes($diagnostics);
        $this->discoverHandlers($diagnostics);
        $this->discoverParts($diagnostics);
        $this->discoverSsrComponents($diagnostics);
    }

    private function resetState(): void
    {
        $this->routeRegistry->reset();
        $this->httpRequests = [];
        $this->httpHandlers = [];
        $this->handlersByPayloadAndResource = [];
        $this->resolvedResponseAttrs = [];
        $this->responseClassAliases = [];
        $this->payloadParts = [];
        $this->resourceParts = [];
        $this->payloadBaseMap = [];
        $this->resourceBaseMap = [];
    }

    private function discoverPayloadsAndRoutes(BootDiagnostics $diagnostics): void
    {
        // Runtime discovery: accept payloads from active modules and project src/
        $allPayloadClasses = $this->classDiscovery->findClassesWithAttribute(AsPayload::class);
        $httpRequestClasses = array_values(array_filter(
            $allPayloadClasses,
            fn (string $class) => $this->moduleRegistry->isClassActive($class) || self::isProjectPayload($class)
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
                    $this->payloadBaseMap[$className] = $meta['attr']['base'];
                    $this->payloadPartRegistry->registerPayloadBase($className, $meta['attr']['base']);
                }
                $groupKey = $meta['attr']['base'] ?? $className;
                $requestGroups[$groupKey][] = $meta;
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Payload reflection failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        // Process responses before finalizing requests
        $this->processResponseAttributes($diagnostics);

        // Build flat list of all requests with resolved path/methods, then group by route and apply override chain
        $resolvedCache = [];
        $byRoute = [];
        foreach (array_keys($requestMeta) as $className) {
            try {
                $resolved = $this->resolveRequestAttributes($className, $requestMeta, $resolvedCache);
                $meta = $requestMeta[$className];
                $overrides = $meta['attr']['overrides'] ?? null;
                $methods = array_values(array_filter(
                    is_array($resolved['methods'] ?? null) ? $resolved['methods'] : ['GET'],
                    static fn (mixed $method): bool => is_string($method) && $method !== '',
                ));
                if ($methods === []) {
                    $methods = ['GET'];
                }
                sort($methods);
                $routeKey = $resolved['path'] . "\0" . implode(',', array_map('strtoupper', $methods));
                $moduleName = $this->moduleRegistry->getModuleNameForClass($className) ?? 'project';
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

            $this->httpRequests[$class] = [
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
                $responseClass = is_string($resolved['responseWith'] ?? null) ? $resolved['responseWith'] : null;
                if ($responseClass !== null) {
                    $resolvedResp = $this->getResolvedResponseAttributes($responseClass);
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

            $this->routeRegistry->register([
                'path' => $resolved['path'],
                'methods' => $resolved['methods'],
                'name' => $resolved['name'],
                'class' => $class,
                'responseClass' => $resolved['responseWith'] ?? null,
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
        }
    }

    private function discoverHandlers(BootDiagnostics $diagnostics): void
    {
        // Find handlers and map to requests (Semitexa packages + project App\ handlers)
        $httpHandlerClasses = array_filter(
            $this->classDiscovery->findClassesWithAttribute(AsPayloadHandler::class),
            fn (string $class) => (
                (str_starts_with($class, 'Semitexa\\') || str_starts_with($class, 'App\\Modules\\'))
                && $this->moduleRegistry->isClassActive($class)
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
                        'transport' => is_string($transport) && $transport !== '' ? $transport : null,
                        'queue' => is_string($queue) && $queue !== '' ? $queue : null,
                        'priority' => $priority,
                        'maxRetries' => $attr->maxRetries,
                        'retryDelay' => $attr->retryDelay,
                    ];
                    $key = $payloadClass . "\0" . $resourceClass;
                    if (!isset($this->handlersByPayloadAndResource[$key])) {
                        $this->handlersByPayloadAndResource[$key] = [];
                    }
                    $this->handlersByPayloadAndResource[$key][] = $handlerMeta;
                    $this->httpHandlers[$class->getName()] = $handlerMeta;

                    // Dual-write to HandlerRegistry
                    $this->handlerRegistry->register($payloadClass, $resourceClass, $handlerMeta);

                    // Warm reflection cache for TypedHandlerInterface handlers
                    if ($class->implementsInterface(TypedHandlerInterface::class)) {
                        try {
                            HandlerReflectionCache::warm($class->getName());
                        } catch (\LogicException $e) {
                            throw new ConfigurationException(
                                "Failed to warm reflection cache for TypedHandlerInterface handler {$class->getName()}: " . $e->getMessage(),
                                $e
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Handler reflection failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        $this->assertPayloadsHaveDiscoveredRoutes();
    }

    private function discoverParts(BootDiagnostics $diagnostics): void
    {
        $this->discoverPayloadParts($diagnostics);
        $this->discoverResourceParts($diagnostics);
    }

    private function discoverSsrComponents(BootDiagnostics $diagnostics): void
    {
        // Discover layout slot contributions (optional)
        if (
            class_exists('Semitexa\\Ssr\\Attribute\\AsLayoutSlot')
            && class_exists('Semitexa\\Ssr\\Layout\\LayoutSlotRegistry')
        ) {
            $slotAttribute = 'Semitexa\\Ssr\\Attribute\\AsLayoutSlot';
            $slotClasses = $this->classDiscovery->findClassesWithAttribute($slotAttribute);
            foreach ($slotClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($slotAttribute);
                    foreach ($attrs as $attr) {
                        /** @var \Semitexa\Ssr\Attribute\AsLayoutSlot $meta */
                        $meta = $attr->newInstance();
                        $handle = $meta->handle;
                        $slot = $meta->slot;
                        $template = EnvValueResolver::resolve($meta->template);
                        $context = EnvValueResolver::resolve($meta->context);
                        \Semitexa\Ssr\Layout\LayoutSlotRegistry::register(
                            $handle,
                            $slot,
                            $template,
                            is_array($context) ? $context : [],
                            $meta->priority,
                            $meta->deferred,
                            $meta->cacheTtl,
                            $meta->dataProvider,
                            $meta->skeletonTemplate,
                            $meta->mode,
                            $meta->refreshInterval,
                        );
                    }
                } catch (\Throwable $e) {
                    $diagnostics->skip('AttributeDiscovery', "Layout slot failed for {$className}: " . $e->getMessage(), $e);
                }
            }
        }

        // Discover DataProvider registrations (optional)
        if (
            class_exists('Semitexa\\Ssr\\Attribute\\AsDataProvider')
            && class_exists('Semitexa\\Ssr\\Application\\Service\\DataProviderRegistry')
        ) {
            $dpAttribute = 'Semitexa\\Ssr\\Attribute\\AsDataProvider';
            $dpClasses = array_values(array_filter(
                $this->classDiscovery->findClassesWithAttribute($dpAttribute),
                fn (string $class) => $this->moduleRegistry->isClassActive($class) || self::isProjectResource($class)
            ));
            foreach ($dpClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($dpAttribute);
                    foreach ($attrs as $attr) {
                        $meta = $attr->newInstance();
                        if ($meta->slot === '') {
                            throw new ConfigurationException("AsDataProvider on {$className} is missing slot.");
                        }
                        $slotId = $meta->slot;
                        $handles = array_values(array_filter(
                            $meta->handles,
                            static fn (string $handle): bool => $handle !== '',
                        ));
                        \Semitexa\Ssr\Application\Service\DataProviderRegistry::register(
                            $slotId,
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
            class_exists('Semitexa\\Ssr\\Attribute\\AsSlotResource')
            && class_exists('Semitexa\\Ssr\\Layout\\LayoutSlotRegistry')
        ) {
            $slotResourceAttribute = 'Semitexa\\Ssr\\Attribute\\AsSlotResource';
            $slotResourceClasses = array_values(array_filter(
                $this->classDiscovery->findClassesWithAttribute($slotResourceAttribute),
                fn (string $class) => $this->moduleRegistry->isClassActive($class) || self::isProjectResource($class)
            ));
            foreach ($slotResourceClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($slotResourceAttribute);
                    foreach ($attrs as $attr) {
                        /** @var \Semitexa\Ssr\Attribute\AsSlotResource $meta */
                        $meta = $attr->newInstance();
                        /** @var string $template */
                        $template = EnvValueResolver::resolve($meta->template);
                        $context = EnvValueResolver::resolve($meta->context);
                        $clientModules = array_values(array_filter(
                            $meta->clientModules,
                            static fn (string $module): bool => $module !== ''
                        ));
                        \Semitexa\Ssr\Layout\LayoutSlotRegistry::register(
                            handle: $meta->handle,
                            slot: $meta->slot,
                            template: $template,
                            context: is_array($context) ? $context : [],
                            priority: $meta->priority,
                            deferred: $meta->deferred,
                            cacheTtl: $meta->cacheTtl,
                            dataProvider: null,
                            skeletonTemplate: $meta->skeletonTemplate,
                            mode: $meta->mode,
                            refreshInterval: $meta->refreshInterval,
                            resourceClass: $className,
                            clientModules: $clientModules,
                        );
                    }
                } catch (\Throwable $e) {
                    $diagnostics->skip('AttributeDiscovery', "Slot resource failed for {$className}: " . $e->getMessage(), $e);
                }
            }
        }

        // Discover AsSlotHandler contributions (optional)
        if (
            class_exists('Semitexa\\Ssr\\Attribute\\AsSlotHandler')
            && class_exists('Semitexa\\Ssr\\Layout\\SlotHandlerRegistry')
        ) {
            $slotHandlerAttribute = 'Semitexa\\Ssr\\Attribute\\AsSlotHandler';
            $slotHandlerClasses = array_values(array_filter(
                $this->classDiscovery->findClassesWithAttribute($slotHandlerAttribute),
                fn (string $class) => $this->moduleRegistry->isClassActive($class) || self::isProjectResource($class)
            ));
            foreach ($slotHandlerClasses as $className) {
                try {
                    $class = new \ReflectionClass($className);
                    $attrs = $class->getAttributes($slotHandlerAttribute);
                    foreach ($attrs as $attr) {
                        /** @var \Semitexa\Ssr\Attribute\AsSlotHandler $meta */
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

    private function resolveRequestAttributes(string $className, array $metaMap, array &$cache = []): array
    {
        if (isset($cache[$className])) {
            return $cache[$className];
        }
        if (!isset($metaMap[$className])) {
            throw new ConfigurationException("Request metadata missing for {$className}");
        }
        $meta = $metaMap[$className];
        $attr = $meta['attr'];
        if (!empty($attr['base'])) {
            $baseClass = is_string($attr['base']) ? $attr['base'] : '';
            $baseAttr = $this->resolveRequestAttributes($baseClass, $metaMap, $cache);
            $merged = self::mergeRequestAttributes($baseAttr, $attr);
        } else {
            $merged = self::applyRequestDefaults($attr, $meta['short'], $className);
        }
        if (!empty($merged['responseWith'])) {
            $responseWith = is_string($merged['responseWith']) ? $merged['responseWith'] : null;
            $merged['responseWith'] = $this->canonicalResponseClass($responseWith);
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
            throw new ConfigurationException("Request {$className} must define a path");
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

    private function processResponseAttributes(BootDiagnostics $diagnostics): void
    {
        // Runtime discovery: accept resources from active modules and project src/
        $allResourceClasses = $this->classDiscovery->findClassesWithAttribute(AsResource::class);
        $responseClasses = array_values(array_filter(
            $allResourceClasses,
            fn (string $class) => $this->moduleRegistry->isClassActive($class) || self::isProjectResource($class)
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
                    $this->resourceBaseMap[$className] = $meta['attr']['base'];
                    $this->payloadPartRegistry->registerResourceBase($className, $meta['attr']['base']);
                }
                $groupKey = $meta['attr']['base'] ?? $className;
                $responseGroups[$groupKey][] = $meta;

                $this->responseClassAliases[$className] = $className;
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Resource reflection failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        if (empty($responseMeta)) {
            return;
        }

        $cache = [];
        foreach ($responseMeta as $className => $meta) {
            $this->resolvedResponseAttrs[$className] = self::resolveResponseAttributes($className, $responseMeta, $cache);
        }

        foreach ($responseGroups as $baseClass => $candidates) {
            usort($candidates, fn ($a, $b) => $b['priority'] <=> $a['priority']);
            $selected = $candidates[0]['class'];
            foreach ($candidates as $candidate) {
                $this->responseClassAliases[$candidate['class']] = $selected;
            }
        }
    }

    private static function resolveResponseAttributes(string $className, array $metaMap, array &$cache = []): array
    {
        if (isset($cache[$className])) {
            return $cache[$className];
        }
        if (!isset($metaMap[$className])) {
            throw new ConfigurationException("Response metadata missing for {$className}");
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

    private function canonicalResponseClass(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }
        return $this->responseClassAliases[$class] ?? $class;
    }

    public function getResolvedResponseAttributes(string $class): ?array
    {
        $canonical = $this->responseClassAliases[$class] ?? $class;
        return $this->resolvedResponseAttrs[$canonical] ?? null;
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
                throw new ConfigurationException(
                    "Request {$c['class']} declares overrides of {$overrides}, but there is no request for this route to override. " .
                    "Remove the overrides attribute (registry is the single source of truth; registry payloads extend module base)."
                );
            }
            $headClass = $head['class'];
            if ($overrides !== $headClass) {
                throw new ConfigurationException(
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

    /**
     * Discover traits marked with #[AsPayloadPart] from active modules.
     */
    private function discoverPayloadParts(BootDiagnostics $diagnostics): void
    {
        $classes = $this->classDiscovery->findClassesWithAttribute(AsPayloadPart::class);
        foreach ($classes as $className) {
            if (!$this->moduleRegistry->isClassActive($className) && !self::isProjectPayload($className)) {
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
                    $this->payloadParts[$base][] = $className;
                    $this->payloadPartRegistry->registerPayloadPart($base, $className);
                }
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Payload part failed for {$className}: " . $e->getMessage(), $e);
            }
        }
    }

    private function discoverResourceParts(BootDiagnostics $diagnostics): void
    {
        $classes = $this->classDiscovery->findClassesWithAttribute(AsResourcePart::class);
        foreach ($classes as $className) {
            if (!$this->moduleRegistry->isClassActive($className) && !self::isProjectResource($className)) {
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
                    $this->resourceParts[$base][] = $className;
                    $this->payloadPartRegistry->registerResourcePart($base, $className);
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
    public function getPayloadPartsForClass(string $requestClass): array
    {
        $this->initialize();
        $chain = self::buildBaseChain($requestClass, $this->payloadBaseMap);
        $traits = [];
        foreach ($this->payloadParts as $base => $traitList) {
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
    public function getResourcePartsForClass(string $responseClass): array
    {
        $this->initialize();
        $chain = self::buildBaseChain($responseClass, $this->resourceBaseMap);
        $traits = [];
        foreach ($this->resourceParts as $base => $traitList) {
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
