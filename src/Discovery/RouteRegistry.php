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
    /** @var list<array> All raw routes (flat list) */
    private array $routes = [];

    /** @var array<string, list<array>> Exact match index: "METHOD:path" => [route, ...] */
    private array $exactIndex = [];

    /** @var list<array{route: array, regex: string, methods: list<string>}> Pre-compiled pattern routes */
    private array $patternIndex = [];

    /** @var array<string, array> Named route index: "name" => route */
    private array $namedIndex = [];

    /**
     * Register a route and add it to the appropriate index.
     * Called during discovery — not after boot.
     */
    public function register(array $route): void
    {
        $this->routes[] = $route;

        $path = $route['path'] ?? '';
        $methods = (array) ($route['methods'] ?? [$route['method'] ?? 'GET']);
        $name = $route['name'] ?? null;

        if ($name !== null && $name !== '') {
            $this->namedIndex[$name] = $route;
        }

        if (str_contains($path, '{')) {
            $regex = self::compilePattern($path, $route['requirements'] ?? []);
            $this->patternIndex[] = [
                'route' => $route,
                'regex' => $regex,
                'methods' => $methods,
            ];
        } else {
            foreach ($methods as $method) {
                $key = $method . ':' . ($path === '' ? '/' : $path);
                $this->exactIndex[$key][] = $route;
            }
        }
    }

    /**
     * Find a raw (non-enriched) route by path and method.
     *
     * @return array|null The matched route or null
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

        $selected = TenantModuleScopeResolver::selectRoutesForCurrentTenant($matches);
        return $selected[0] ?? null;
    }

    /**
     * Find a raw route by its name.
     */
    public function findByName(string $name): ?array
    {
        return $this->namedIndex[$name] ?? null;
    }

    /**
     * Get all raw routes.
     *
     * @return list<array>
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

    /**
     * Compile a route path pattern into a regex string.
     */
    private static function compilePattern(string $path, array $requirements): string
    {
        $placeholders = [];
        $tempPath = preg_replace_callback(
            '/\{([^}]+)\}/',
            function ($m) use (&$placeholders, $requirements) {
                $placeholder = '__PLACEHOLDER_' . count($placeholders) . '__';
                $paramName = $m[1];
                $placeholders[$placeholder] = '(' . ($requirements[$paramName] ?? '[^/]+') . ')';
                return $placeholder;
            },
            $path
        );

        $pattern = preg_quote($tempPath, '#');

        foreach ($placeholders as $placeholder => $regex) {
            $pattern = str_replace($placeholder, $regex, $pattern);
        }

        return '#^' . $pattern . '$#';
    }
}
