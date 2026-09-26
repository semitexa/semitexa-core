<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attribute\TransportType;
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

    /**
     * Exact routes by path with the methods they answer, for allowedMethods().
     *
     * @var array<string, list<array{route: array<string, mixed>, methods: list<string>}>>
     */
    private array $exactByPath = [];

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

        // HEAD is GET without the body (RFC 9110 §9.3.2), and a server that
        // answers GET must answer HEAD. Like OPTIONS it goes into the lookup
        // index only, so a HEAD resolves to the very route — handler, auth and
        // headers — a GET would; the HTTP server drops the body on the way out.
        //
        // Not for SSE/stream routes: their handlers take the raw Swoole
        // response and write status, headers and chunks themselves, past the
        // emitter that withholds the body — a HEAD would open a live stream
        // and send a body a HEAD response must not have. They keep answering
        // HEAD with 404/405, as before.
        $transport = is_string($route['transport'] ?? null) ? $route['transport'] : '';
        $streams = $transport === TransportType::Sse->value || $transport === TransportType::Stream->value;
        if (!$streams && in_array('GET', $methods, true) && !in_array('HEAD', $indexMethods, true)) {
            $indexMethods[] = 'HEAD';
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
            $this->exactByPath[$path === '' ? '/' : $path][] = ['route' => $route, 'methods' => $indexMethods];
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

        // A route that declares HEAD itself wins over a GET route that only
        // answers HEAD through the synthesized index entry, whichever was
        // registered first. Partitioned per tier, so an exact match still
        // precedes a pattern match.
        if ($method === 'HEAD') {
            $exactCount = isset($this->exactIndex[$key]) ? count($this->exactIndex[$key]) : 0;
            $matches = [
                ...self::declaringFirst(array_slice($matches, 0, $exactCount), 'HEAD'),
                ...self::declaringFirst(array_slice($matches, $exactCount), 'HEAD'),
            ];
        }

        $selected = TenantModuleScopeResolver::selectRoutesForTenant($matches, $this->currentTenantContext());
        $selectedRoute = $selected[0] ?? null;
        return is_array($selectedRoute) ? $selectedRoute : null;
    }

    /**
     * The methods some route answers on this path — non-empty when find()
     * missed only because of the method, which is a 405 with this list as
     * Allow, not a 404 (RFC 9110 §15.5.6). Honours tenant module scope the
     * way find() does, so a route hidden from this tenant stays a 404.
     *
     * @return list<string>
     */
    public function allowedMethods(string $path): array
    {
        if ($path === '') {
            $path = '/';
        }

        $candidates = $this->exactByPath[$path] ?? [];
        foreach ($this->patternIndex as $compiled) {
            if (preg_match($compiled['regex'], $path)) {
                $candidates[] = ['route' => $compiled['route'], 'methods' => $compiled['methods']];
            }
        }

        $context = $this->currentTenantContext();
        $methods = [];
        foreach ($candidates as $candidate) {
            if (TenantModuleScopeResolver::selectRoutesForTenant([$candidate['route']], $context) === []) {
                continue;
            }
            foreach ($candidate['methods'] as $method) {
                $methods[$method] = true;
            }
        }

        $methods = array_keys($methods);
        sort($methods);

        return $methods;
    }

    /**
     * Stable partition: routes that declare $method first, the rest after.
     *
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    private static function declaringFirst(array $routes, string $method): array
    {
        $declaring = [];
        $others = [];
        foreach ($routes as $route) {
            $methods = is_array($route['methods'] ?? null) ? $route['methods'] : [$route['method'] ?? 'GET'];
            if (in_array($method, $methods, true)) {
                $declaring[] = $route;
            } else {
                $others[] = $route;
            }
        }

        return [...$declaring, ...$others];
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
        $this->exactByPath = [];
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
