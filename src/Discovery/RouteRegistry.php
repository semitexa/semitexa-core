<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Support\TenantModuleScopeResolver;

/**
 * Pre-compiled route index built at boot time.
 *
 * Provides O(1) exact-match lookups and pre-compiled regex for pattern routes.
 * Tenant filtering is applied at lookup time since the active tenant varies per request.
 */
class RouteRegistry
{
    /** @var null|\Closure(): ?\Semitexa\Core\Tenant\TenantContextInterface */
    private ?\Closure $tenantContextProvider = null;

    /** @var list<array<string, mixed>> All raw routes (flat list) */
    private array $routes = [];

    /** @var array<string, list<array<string, mixed>>> Exact match index: "METHOD:path" => [route, ...] */
    private array $exactIndex = [];

    /** @var list<array{route: array<string, mixed>, regex: string, methods: list<string>}> Pre-compiled pattern routes */
    private array $patternIndex = [];

    /** @var array<string, list<array<string, mixed>>> Named route index: "name" => [route, ...] */
    private array $namedIndex = [];

    /**
     * Register a route and add it to the appropriate index.
     * Called during discovery — not after boot.
     *
     * @param array<string, mixed> $route
     */
    public function register(array $route): void
    {
        $this->routes[] = $route;

        $path = is_string($route['path'] ?? null) ? $route['path'] : '';
        $methods = array_values(array_filter(
            is_array($route['methods'] ?? null) ? $route['methods'] : [$route['method'] ?? 'GET'],
            static fn (mixed $method): bool => is_string($method) && $method !== '',
        ));
        if ($methods === []) {
            $methods = ['GET'];
        }
        $name = is_string($route['name'] ?? null) ? $route['name'] : null;

        if ($name !== null && $name !== '') {
            $this->namedIndex[$name][] = $route;
        }

        // Multi-Modal API — Mode 4: every routable payload endpoint also answers
        // OPTIONS, served by the generic OptionsMetadataHandler. We add OPTIONS
        // only to the lookup index (`$indexMethods`); the route's own `methods`
        // are left untouched so the emitted metadata document and every other
        // consumer keep reporting the declared verbs (e.g. ["GET"]). Auth is
        // inherited for free: an OPTIONS request resolves to this same route
        // (same `class`/payload + accessType), so AuthorizationListener enforces
        // identically. Gated to discovered HTTP payload routes (those carry a
        // non-empty request class); routes that already declare OPTIONS keep it.
        $type = is_string($route['type'] ?? null) ? $route['type'] : '';
        $class = is_string($route['class'] ?? null) ? $route['class'] : '';
        $indexMethods = $methods;
        if ($type === 'http-request' && $class !== '' && !in_array('OPTIONS', $methods, true)) {
            $indexMethods = [...$methods, 'OPTIONS'];
        }

        if (str_contains($path, '{')) {
            /** @var array<string, mixed> $requirements */
            $requirements = is_array($route['requirements'] ?? null) ? $route['requirements'] : [];
            $regex = self::compilePattern($path, $requirements);
            $this->patternIndex[] = [
                'route' => $route,
                'regex' => $regex,
                'methods' => $indexMethods,
            ];
        } else {
            foreach ($indexMethods as $method) {
                $key = $method . ':' . ($path === '' ? '/' : $path);
                $this->exactIndex[$key][] = $route;
            }
        }
    }

    /**
     * Find a raw (non-enriched) route by path and method.
     *
     * @return array<string, mixed>|null The matched route or null
     */
    public function find(string $path, string $method = 'GET'): ?array
    {
        if ($path === '') {
            $path = '/';
        }

        $matches = [];

        // O(1) exact match
        $key = $method . ':' . $path;
        if (isset($this->exactIndex[$key])) {
            foreach ($this->exactIndex[$key] as $route) {
                $matches[] = $route;
            }
        }

        // Pattern matching with pre-compiled regex
        foreach ($this->patternIndex as $compiled) {
            if (!in_array($method, $compiled['methods'], true)) {
                continue;
            }
            if (preg_match($compiled['regex'], $path)) {
                $matches[] = $compiled['route'];
            }
        }

        if ($matches === []) {
            return null;
        }

        $selected = TenantModuleScopeResolver::selectRoutesForTenant($matches, $this->currentTenantContext());
        $selectedRoute = $selected[0] ?? null;
        return is_array($selectedRoute) ? $selectedRoute : null;
    }

    /**
     * Find a raw route by its name.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $matches = $this->namedIndex[$name] ?? [];
        if ($matches === []) {
            return null;
        }

        $selected = TenantModuleScopeResolver::selectRoutesForTenant($matches, $this->currentTenantContext());
        $selectedRoute = $selected[0] ?? null;
        return is_array($selectedRoute) ? $selectedRoute : null;
    }

    /**
     * Find a typed route by path and method.
     * When a HandlerRegistry is provided, the returned route includes resolved handlers.
     */
    public function findRouteTyped(string $path, string $method = 'GET', ?HandlerRegistry $handlerRegistry = null): ?DiscoveredRoute
    {
        $route = $this->find($path, $method);
        if ($route === null) {
            return null;
        }

        if ($handlerRegistry !== null) {
            $requestClass = is_string($route['class'] ?? null) ? $route['class'] : '';
            $responseClass = is_string($route['responseClass'] ?? null) ? $route['responseClass'] : null;
            $route['handlers'] = $handlerRegistry->findHandlers($requestClass, $responseClass);
        }

        return DiscoveredRoute::fromArray($route);
    }

    /**
     * Find a typed route by name.
     * When a HandlerRegistry is provided, the returned route includes resolved handlers.
     */
    public function findByNameTyped(string $name, ?HandlerRegistry $handlerRegistry = null): ?DiscoveredRoute
    {
        $route = $this->findByName($name);
        if ($route === null) {
            return null;
        }

        if ($handlerRegistry !== null) {
            $requestClass = is_string($route['class'] ?? null) ? $route['class'] : '';
            $responseClass = is_string($route['responseClass'] ?? null) ? $route['responseClass'] : null;
            $route['handlers'] = $handlerRegistry->findHandlers($requestClass, $responseClass);
        }

        return DiscoveredRoute::fromArray($route);
    }

    /**
     * Rebuild a typed route with a different responseClass and re-resolve
     * its handlers against the new (request, response) pair.
     *
     * Cross-profile dispatch: a route declared with `responsesByProfile`
     * (e.g. JSON + JSON-LD + GraphQL variants on the same path) is built
     * once with the legacy `responseClass`. At request time
     * `CrossProfileDispatcher` picks the actual response class from the
     * Accept header; the registered handler list — which is keyed on
     * (requestClass, responseClass) — must then be re-resolved or the
     * pipeline ends up trying to instantiate the wrong DTO.
     *
     * Returns a fresh `DiscoveredRoute` with `responseClass` swapped in
     * and `handlers` re-resolved when a `HandlerRegistry` is supplied.
     * Without a `HandlerRegistry` the original handler list is preserved
     * — callers that don't have one still get a route with the right
     * `responseClass`, which is enough for the response-DTO factory.
     */
    public function rebindHandlersForResponse(
        DiscoveredRoute $route,
        string $responseClass,
        ?HandlerRegistry $handlerRegistry = null,
    ): DiscoveredRoute {
        $handlers = $handlerRegistry !== null
            ? $handlerRegistry->findHandlers($route->requestClass, $responseClass)
            : $route->handlers;

        return new DiscoveredRoute(
            path: $route->path,
            methods: $route->methods,
            name: $route->name,
            requestClass: $route->requestClass,
            responseClass: $responseClass,
            handlers: $handlers,
            type: $route->type,
            transport: $route->transport,
            produces: $route->produces,
            consumes: $route->consumes,
            module: $route->module,
            requirements: $route->requirements,
            defaults: $route->defaults,
            options: $route->options,
            tags: $route->tags,
            accessType: $route->accessType,
            tenantScopes: $route->tenantScopes,
            renderProfile: $route->renderProfile,
            responsesByProfile: $route->responsesByProfile,
        );
    }

    /**
     * Get all raw routes.
     *
     * @return list<array<string, mixed>>
     */
    public function getAll(): array
    {
        return $this->routes;
    }

    /**
     * Reset all indexes. Called during discovery reset.
     */
    public function reset(): void
    {
        $this->routes = [];
        $this->exactIndex = [];
        $this->patternIndex = [];
        $this->namedIndex = [];
    }

    public function setTenantContextProvider(\Closure $provider): void
    {
        $this->tenantContextProvider = $provider;
    }

    /**
     * Compile a route path pattern into a regex string.
     *
     * @param array<string, mixed> $requirements
     */
    private static function compilePattern(string $path, array $requirements): string
    {
        $placeholders = [];
        $tempPath = preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $m) use (&$placeholders, $requirements): string {
                $placeholder = '__PLACEHOLDER_' . count($placeholders) . '__';
                $paramName = (string) $m[1];
                $requirement = $requirements[$paramName] ?? '[^/]+';
                $placeholders[$placeholder] = '(' . (is_string($requirement) ? $requirement : '[^/]+') . ')';
                return $placeholder;
            },
            $path
        );
        if (!is_string($tempPath)) {
            return '#^$#';
        }

        $pattern = preg_quote($tempPath, '#');

        foreach ($placeholders as $placeholder => $regex) {
            $pattern = str_replace($placeholder, $regex, $pattern);
        }

        return '#^' . $pattern . '$#';
    }

    private function currentTenantContext(): ?\Semitexa\Core\Tenant\TenantContextInterface
    {
        if ($this->tenantContextProvider === null) {
            return null;
        }

        $context = ($this->tenantContextProvider)();

        return $context instanceof \Semitexa\Core\Tenant\TenantContextInterface ? $context : null;
    }
}
