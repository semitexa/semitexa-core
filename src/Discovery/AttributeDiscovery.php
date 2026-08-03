<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsPayloadPart;
use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Attribute\AsResourcePart;
use Semitexa\Core\Attribute\AsDiscoveryContributor;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Environment;
use Semitexa\Core\ModuleRegistry;
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
/**
 * @phpstan-type Route   array<string, mixed>
 * @phpstan-type AttrMap array<string, mixed>
 */
class AttributeDiscovery
{
    /** @var array<string, AttrMap> */
    private array $httpRequests = [];
    /** @var array<string, AttrMap> */
    private array $resolvedResponseAttrs = [];
    /** @var array<string, string> */
    private array $responseClassAliases = [];
    private bool $initialized = false;
    /** Set for the duration of initialize() so a contributor cannot re-enter it. */
    private bool $initializing = false;

    private readonly HandlerRegistry $handlerRegistry;
    private readonly PayloadPartRegistry $payloadPartRegistry;
    private readonly SourceOrigin $sourceOrigin;

    /** Chain resolution for #[AsPublicPayload] & siblings. */
    private readonly AttributeChainResolver $payloadAttributes;

    /** Chain resolution for #[AsResource]. */
    private readonly AttributeChainResolver $resourceAttributes;

    /** Fatal boot guards for a route that is wrong rather than merely broken. */
    private readonly RouteDeclarationGuard $routeGuard;

    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly RouteRegistry $routeRegistry,
        ?HandlerRegistry $handlerRegistry = null,
        ?PayloadPartRegistry $payloadPartRegistry = null,
        ?SourceOrigin $sourceOrigin = null,
    ) {
        $this->handlerRegistry = $handlerRegistry ?? new HandlerRegistry();
        $this->payloadPartRegistry = $payloadPartRegistry ?? new PayloadPartRegistry();
        $this->sourceOrigin = $sourceOrigin ?? new SourceOrigin();
        $this->payloadAttributes = new AttributeChainResolver(new PayloadAttributeSchema());
        $this->resourceAttributes = new AttributeChainResolver(new ResourceAttributeSchema());
        $this->routeGuard = new RouteDeclarationGuard();
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
        // `$initialized` alone is not enough to make this idempotent. It is set
        // only once the scan below returns, and the scan runs third-party
        // contribute() implementations; a contributor that reaches any accessor
        // which boots discovery — getPayloadPartsForClass() and
        // getResourcePartsForClass() both call initialize() — would re-enter here
        // with the flag still false and restart the whole scan underneath itself.
        // Deep enough, that is stack exhaustion, which is a fatal the \Throwable
        // handler inside the scan cannot contain; shallow enough, it double-registers
        // every handler and part. The in-progress flag makes the re-entrant call a
        // no-op instead, so the outer scan is the only one that runs.
        if ($this->initialized || $this->initializing) {
            return;
        }

        $this->initializing = true;

        try {
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
        } finally {
            // Cleared even on a throw, or a failed boot would leave discovery
            // permanently refusing to initialize with no way back.
            $this->initializing = false;
        }
    }

    /**
     * Get all discovered routes
     *
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return $this->routeRegistry->getAll();
    }

    /**
     * Get all discovered routes with responseClass and handlers populated.
     *
     * @return list<Route>
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

        return $this->handlerRegistry->getHandlerClassNames();
    }

    /**
     * Find a route by path and method.
     * Delegates to RouteRegistry for indexed lookup, then enriches with handler/response data.
     *
     * @return Route|null
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
     *
     * @return Route|null
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
     *
     * @param  Route $route
     * @return Route
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
                $route['handlers'] = $this->handlerRegistry->findHandlers($reqClass, $responseClass);
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
        foreach ($this->handlerRegistry->payloadClasses() as $payloadClass) {
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
                "Ensure the payload class declares one of #[AsPublicPayload], #[AsProtectedPayload], or #[AsServicePayload] and belongs to an active module or project src/."
            );
        }
    }

    private function scanAttributesIntelligently(): void
    {
        $diagnostics = BootDiagnostics::current();

        $this->resetState();
        $this->discoverPayloadsAndRoutes($diagnostics);
        $this->discoverHandlers($diagnostics);
        $this->discoverParts($diagnostics);
        $this->discoverContributedComponents($diagnostics);
    }

    private function resetState(): void
    {
        $this->routeRegistry->reset();
        $this->httpRequests = [];
        $this->resolvedResponseAttrs = [];
        $this->responseClassAliases = [];
    }

    private function discoverPayloadsAndRoutes(BootDiagnostics $diagnostics): void
    {
        $requestMeta = $this->collectPayloadMetadata($diagnostics);

        // Process responses before finalizing requests.
        $this->processResponseAttributes($diagnostics);

        // Group requests by route and apply the override chain, then register
        // the winning candidate of each route.
        $byRoute = $this->groupRouteCandidatesByOverride($requestMeta, $diagnostics);
        $this->registerResolvedRoutes($byRoute);
    }

    /**
     * Reflect every routable payload into its raw route metadata, keyed by class.
     *
     * Routable payloads carry one of #[AsPublicPayload]/#[AsProtectedPayload]/
     * #[AsServicePayload], all of which extend AbstractPayloadRoute. Querying by
     * the abstract base via IS_INSTANCEOF keeps semitexa-core decoupled from the
     * concrete attribute classes (which live in semitexa-authorization).
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectPayloadMetadata(BootDiagnostics $diagnostics): array
    {
        $allPayloadClasses = $this->classDiscovery->findClassesWithAttributeInstanceof(AbstractPayloadRoute::class);
        $httpRequestClasses = array_values(array_filter(
            $allPayloadClasses,
            fn (string $class) => $this->moduleRegistry->isClassActive($class) || $this->sourceOrigin->isProjectClass($class, 'payload')
        ));
        $requestMeta = [];
        foreach ($httpRequestClasses as $className) {
            try {
                $class = new ReflectionClass($className);
                $attrs = $class->getAttributes(AbstractPayloadRoute::class, \ReflectionAttribute::IS_INSTANCEOF);
                if (empty($attrs)) {
                    continue;
                }
                /** @var AbstractPayloadRoute $attr */
                $attr = $attrs[0]->newInstance();
                $meta = [
                    'class' => $className,
                    'short' => $class->getShortName(),
                    'file' => $class->getFileName() ?: '',
                    'priority' => $this->sourceOrigin->priorityForFile($class->getFileName() ?: ''),
                    'attr' => [
                        'path' => EnvValueResolver::resolve($attr->path),
                        'methods' => EnvValueResolver::resolve($attr->methods),
                        'name' => $attr->name !== null ? EnvValueResolver::resolve($attr->name) : null,
                        'requirements' => EnvValueResolver::resolve($attr->requirements),
                        'defaults' => EnvValueResolver::resolve($attr->defaults),
                        'options' => EnvValueResolver::resolve($attr->options),
                        'tags' => EnvValueResolver::resolve($attr->tags),
                        'accessType' => $attr->getAccessType(),
                        'responseWith' => $attr->responseWith !== null ? EnvValueResolver::resolve($attr->responseWith) : null,
                        'base' => $attr->base ? ltrim($attr->base, '\\') : null,
                        'overrides' => $attr->overrides ? ltrim($attr->overrides, '\\') : null,
                        'consumes' => $attr->consumes,
                        'produces' => $attr->produces,
                        'transport' => $attr->transport,
                        // SSE gate-model axis (boot guard, see assertSseGateCoherence).
                        // Read in this same IS_INSTANCEOF pass; threaded through the
                        // override-merge so the selected route carries it.
                        'sseGateModel' => $attr->sseGateModel,
                        // Multi-profile dispatch metadata. Both fields
                        // pass through unchanged — RouteExecutor + dispatcher
                        // consume them at request time.
                        'renderProfile' => $attr->renderProfile,
                        'responsesByProfile' => $attr->responsesByProfile,
                    ],
                ];
                $requestMeta[$className] = $meta;
                if ($meta['attr']['base'] !== null) {
                    $this->payloadPartRegistry->registerPayloadBase($className, $meta['attr']['base']);
                }
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Payload reflection failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        return $requestMeta;
    }

    /**
     * Resolve each request's attributes and bucket candidates by their route
     * (path + methods + tenant-scope signature) so the override chain can pick
     * one winner per route.
     *
     * @param array<string, array<string, mixed>> $requestMeta
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupRouteCandidatesByOverride(array $requestMeta, BootDiagnostics $diagnostics): array
    {
        $resolvedCache = [];
        $byRoute = [];
        foreach (array_keys($requestMeta) as $className) {
            try {
                $resolved = $this->resolvePayloadAttributes($className, $requestMeta, $resolvedCache);
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

        return $byRoute;
    }

    /**
     * Pick the override-chain winner of each route bucket and register it on the
     * request map + route registry (running the framework-reserved-path and SSE
     * boot guards on the routed candidate).
     *
     * @param array<string, list<array<string, mixed>>> $byRoute
     */
    private function registerResolvedRoutes(array $byRoute): void
    {
        foreach ($byRoute as $candidates) {
            $selected = self::selectRequestByOverrideChain($candidates);
            if ($selected === null) {
                continue;
            }

            $this->registerRoute($selected);
        }
    }

    /**
     * Admit one override-chain winner: guard it, record it, register it.
     *
     * Guards run before either write. The original ran them after populating the
     * request map, which was harmless — a guard failure aborts the boot, so
     * nobody ever observed the half-written state — but validating before
     * mutating is the order that stays correct if this is ever called somewhere
     * a throw does not end the process.
     *
     * @param array{class: string, file: string, resolved: array<string, mixed>, module: string, tenantScopes: list<string>} $selected
     */
    private function registerRoute(array $selected): void
    {
        $class = $selected['class'];
        $resolved = $selected['resolved'];
        $transportValue = self::normalizeTransport($resolved['transport'] ?? null);

        $this->routeGuard->assertPathNotReserved($resolved['path'], $class);

        $accessType = $resolved['accessType'] ?? null;
        if ($accessType instanceof PayloadAccessType) {
            $this->routeGuard->assertSseGateCoherence(
                $transportValue,
                $resolved['sseGateModel'] ?? null,
                $accessType,
                $class,
            );
        }

        $this->httpRequests[$class] = [
            'requestClass' => $class,
            'path' => $resolved['path'],
            'methods' => $resolved['methods'],
            'name' => $resolved['name'],
            'responseClass' => $resolved['responseWith'],
            'file' => $selected['file'],
            'module' => $selected['module'],
            'tenantScopes' => $selected['tenantScopes'],
            'handlers' => [],
        ];

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
            'accessType' => $resolved['accessType'],
            'type' => 'http-request',
            'transport' => $transportValue,
            'consumes' => $resolved['consumes'] ?? null,
            'produces' => $this->resolveProduces($resolved),
            'module' => $selected['module'],
            'tenantScopes' => $selected['tenantScopes'],
            // Thread the multi-profile metadata through to the
            // routing layer so RouteExecutor + CrossProfileDispatcher can
            // pick the right response class per request.
            'renderProfile' => $resolved['renderProfile'] ?? null,
            'responsesByProfile' => $resolved['responsesByProfile'] ?? null,
        ]);
    }

    /**
     * What the route produces: the payload's own declaration wins, and only when
     * it is silent does the response class's #[AsResource] supply the answer.
     *
     * @param  array<string, mixed> $resolved
     * @return mixed
     */
    private function resolveProduces(array $resolved): mixed
    {
        $produces = $resolved['produces'] ?? null;
        if ($produces !== null) {
            return $produces;
        }

        $responseClass = is_string($resolved['responseWith'] ?? null) ? $resolved['responseWith'] : null;
        if ($responseClass === null) {
            return null;
        }

        $resolvedResponse = $this->getResolvedResponseAttributes($responseClass);

        return $resolvedResponse['produces'] ?? null;
    }

    /**
     * Reduce a declared transport to its wire string, defaulting to HTTP.
     *
     * The attribute normally carries a {@see TransportType}, but the value
     * survives an EnvValueResolver round-trip and an override merge, so a plain
     * string is legitimate here too.
     */
    private static function normalizeTransport(mixed $transport): string
    {
        if ($transport instanceof TransportType) {
            return $transport->value;
        }

        return is_string($transport) && $transport !== ''
            ? $transport
            : TransportType::Http->value;
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

    private function processResponseAttributes(BootDiagnostics $diagnostics): void
    {
        // Runtime discovery: accept resources from active modules and project src/
        $allResourceClasses = $this->classDiscovery->findClassesWithAttribute(AsResource::class);
        $responseClasses = array_values(array_filter(
            $allResourceClasses,
            fn (string $class) => $this->moduleRegistry->isClassActive($class) || $this->sourceOrigin->isProjectClass($class, 'resource')
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
                    'priority' => $this->sourceOrigin->priorityForFile($class->getFileName() ?: ''),
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
            $this->resolvedResponseAttrs[$className] = $this->resourceAttributes->resolve($className, $responseMeta, $cache);
        }

        foreach ($responseGroups as $baseClass => $candidates) {
            usort($candidates, fn ($a, $b) => $b['priority'] <=> $a['priority']);
            $selected = $candidates[0]['class'];
            foreach ($candidates as $candidate) {
                $this->responseClassAliases[$candidate['class']] = $selected;
            }
        }
    }

    /**
     * Resolve a payload's attribute chain, then canonicalize the response class
     * it points at.
     *
     * The canonicalization is the one step the generic resolver cannot do: a
     * payload names the resource it responds with, but several resource classes
     * may share an attribute base, in which case discovery has already elected a
     * single winner among them (see processResponseAttributes). Rewriting
     * `responseWith` to that winner here means every later stage — route
     * registration, handler matching, enrichment — sees one canonical class
     * instead of whichever alias the payload happened to name. It runs after the
     * merge because a child can override `responseWith`.
     *
     * @param  array<string, array{class: string, short: string, attr: AttrMap}> $metaMap
     * @param  array<string, AttrMap>                                            $cache
     * @return AttrMap
     */
    private function resolvePayloadAttributes(string $className, array $metaMap, array &$cache): array
    {
        $resolved = $this->payloadAttributes->resolve($className, $metaMap, $cache);

        if (!empty($resolved['responseWith'])) {
            $responseWith = is_string($resolved['responseWith']) ? $resolved['responseWith'] : null;
            $resolved['responseWith'] = $this->canonicalResponseClass($responseWith);
        }

        return $resolved;
    }

    private function canonicalResponseClass(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }
        return $this->responseClassAliases[$class] ?? $class;
    }

    /**
     * @return AttrMap|null
     */
    public function getResolvedResponseAttributes(string $class): ?array
    {
        $canonical = $this->responseClassAliases[$class] ?? $class;
        return $this->resolvedResponseAttrs[$canonical] ?? null;
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
                $this->sourceOrigin->isProjectClass($class, 'handler')
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

    /**
     * Run every package-supplied {@see DiscoveryContributor}.
     *
     * Core deliberately names no downstream attribute here. It finds the
     * contributors, resolves the classes each one asks about, applies the module
     * scoping each one declares, and hands over the instantiated attribute — the
     * meaning of that attribute stays in the package that defined it.
     *
     * A contributor naming an attribute class that is not loadable is skipped in
     * silence: that simply means its package is not installed.
     */
    private function discoverContributedComponents(BootDiagnostics $diagnostics): void
    {
        foreach ($this->discoveryContributors($diagnostics) as $contributor) {
            $attribute = $contributor->attribute();
            if (!class_exists($attribute)) {
                continue;
            }

            $classes = $this->classDiscovery->findClassesWithAttribute($attribute);
            if ($contributor->scopedToActiveModules()) {
                $classes = array_filter(
                    $classes,
                    fn (string $class): bool => $this->moduleRegistry->isClassActive($class)
                        || $this->sourceOrigin->isProjectClass($class, 'contribution'),
                );
            }

            foreach ($classes as $className) {
                try {
                    foreach ((new ReflectionClass($className))->getAttributes($attribute) as $found) {
                        $contributor->contribute($className, $found->newInstance(), $diagnostics);
                    }
                } catch (\Throwable $e) {
                    $diagnostics->skip(
                        'AttributeDiscovery',
                        sprintf('%s contribution failed for %s: %s', $attribute, $className, $e->getMessage()),
                        $e,
                    );
                }
            }
        }
    }

    /**
     * Instantiate every discovered contributor, highest priority first.
     *
     * Contributors are constructed with no arguments so discovery never has to
     * resolve a container that is still being built around it.
     *
     * @return list<DiscoveryContributor>
     */
    private function discoveryContributors(BootDiagnostics $diagnostics): array
    {
        $found = [];
        foreach ($this->classDiscovery->findClassesWithAttribute(AsDiscoveryContributor::class) as $className) {
            try {
                $class = new ReflectionClass($className);
                if (!$class->implementsInterface(DiscoveryContributor::class) || !$class->isInstantiable()) {
                    $diagnostics->invalidUsage(
                        'AttributeDiscovery',
                        sprintf('%s is #[AsDiscoveryContributor] but is not an instantiable %s.', $className, DiscoveryContributor::class),
                    );
                    continue;
                }

                $attrs = $class->getAttributes(AsDiscoveryContributor::class);
                /** @var AsDiscoveryContributor $meta */
                $meta = $attrs[0]->newInstance();
                /** @var DiscoveryContributor $instance */
                $instance = $class->newInstance();
                $found[] = ['priority' => $meta->priority, 'class' => $className, 'instance' => $instance];
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Discovery contributor failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        // Name breaks priority ties so the boot order is reproducible rather
        // than a function of filesystem scan order.
        usort($found, fn (array $a, array $b): int => [$b['priority'], $a['class']] <=> [$a['priority'], $b['class']]);

        return array_column($found, 'instance');
    }

    /**
     * Discover traits marked with #[AsPayloadPart] from active modules.
     */
    private function discoverPayloadParts(BootDiagnostics $diagnostics): void
    {
        $classes = $this->classDiscovery->findClassesWithAttribute(AsPayloadPart::class);
        foreach ($classes as $className) {
            if (!$this->moduleRegistry->isClassActive($className) && !$this->sourceOrigin->isProjectClass($className, 'payload')) {
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
            if (!$this->moduleRegistry->isClassActive($className) && !$this->sourceOrigin->isProjectClass($className, 'resource')) {
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
                    $this->payloadPartRegistry->registerResourcePart($base, $className);
                }
            } catch (\Throwable $e) {
                $diagnostics->skip('AttributeDiscovery', "Resource part failed for {$className}: " . $e->getMessage(), $e);
            }
        }
    }

    /**
     * Get trait list for a payload class.
     *
     * Boot-triggering wrapper over {@see PayloadPartRegistry}. Discovery used to
     * keep a second copy of the parts and base maps and answer from that; the
     * two stores were dual-written and their lookups were character-for-character
     * identical, so the copy could only ever agree with the registry or be a bug.
     * The registry is now the single store — the value this method still adds is
     * the lazy `initialize()`, which callers holding a not-yet-booted discovery
     * rely on and the registry has no notion of.
     *
     * @return list<string>
     */
    public function getPayloadPartsForClass(string $requestClass): array
    {
        $this->initialize();

        return $this->payloadPartRegistry->getPayloadPartsForClass($requestClass);
    }

    /**
     * Get trait list for a resource class. See {@see getPayloadPartsForClass}.
     *
     * @return list<string>
     */
    public function getResourcePartsForClass(string $responseClass): array
    {
        $this->initialize();

        return $this->payloadPartRegistry->getResourcePartsForClass($responseClass);
    }
}
