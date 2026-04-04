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
    private static array $routes = [];

    /** @var array<string, list<array>> Exact match index: "METHOD:path" => [route, ...] */
    private static array $exactIndex = [];

    /** @var list<array{route: array, regex: string, methods: list<string>}> Pre-compiled pattern routes */
    private static array $patternIndex = [];

    /** @var array<string, array> Named route index: "name" => route */
    private static array $namedIndex = [];

    /**
     * Register a route and add it to the appropriate index.
     * Called during discovery — not after boot.
     */
    public static function register(array $route): void
    {
        self::$routes[] = $route;

        $path = $route['path'] ?? '';
        $methods = (array) ($route['methods'] ?? [$route['method'] ?? 'GET']);
        $name = $route['name'] ?? null;

        if ($name !== null && $name !== '') {
            self::$namedIndex[$name] = $route;
        }

        if (str_contains($path, '{')) {
            // Pattern route — pre-compile regex
            $regex = self::compilePattern($path, $route['requirements'] ?? []);
            self::$patternIndex[] = [
                'route' => $route,
                'regex' => $regex,
                'methods' => $methods,
            ];
        } else {
            // Exact route — index by method:path
            foreach ($methods as $method) {
                $key = $method . ':' . ($path === '' ? '/' : $path);
                self::$exactIndex[$key][] = $route;
            }
        }
    }

    /**
     * Find a raw (non-enriched) route by path and method.
     *
     * @return array|null The matched route or null
     */
    public static function find(string $path, string $method = 'GET'): ?array
    {
        if ($path === '') {
            $path = '/';
        }

        $matches = [];

        // O(1) exact match
        $key = $method . ':' . $path;
        if (isset(self::$exactIndex[$key])) {
            foreach (self::$exactIndex[$key] as $route) {
                $matches[] = $route;
            }
        }

        // Pattern matching with pre-compiled regex
        foreach (self::$patternIndex as $compiled) {
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
    public static function findByName(string $name): ?array
    {
        return self::$namedIndex[$name] ?? null;
    }

    /**
     * Get all raw routes.
     *
     * @return list<array>
     */
    public static function getAll(): array
    {
        return self::$routes;
    }

    /**
     * Reset all indexes. Called during discovery reset.
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$exactIndex = [];
        self::$patternIndex = [];
        self::$namedIndex = [];
    }

    /**
     * Compile a route path pattern into a regex string.
     * E.g., "/users/{id}" becomes "#^/users/([^/]+)$#"
     * Requirements come from the $requirements array keyed by parameter name.
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
