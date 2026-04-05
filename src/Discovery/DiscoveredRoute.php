<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Immutable, typed representation of a discovered route.
 *
 * Dual-write companion to the existing array-based route format.
 * Phase 1: created alongside arrays; consumers migrate in Phase 3.
 */
final readonly class DiscoveredRoute
{
    /**
     * @param list<string>        $methods
     * @param list<string>        $handlers
     * @param list<string>|null   $produces
     * @param list<string>|null   $consumes
     * @param array<string, string> $requirements
     * @param array<string, mixed>  $defaults
     * @param array<string, mixed>  $options
     * @param list<string>        $tags
     * @param list<string>        $tenantScopes
     */
    public function __construct(
        public string $path,
        public array $methods,
        public ?string $name,
        public string $requestClass,
        public ?string $responseClass,
        public array $handlers,
        public string $type,
        public ?array $produces,
        public ?array $consumes,
        public string $module,
        public array $requirements = [],
        public array $defaults = [],
        public array $options = [],
        public array $tags = [],
        public bool $public = false,
        public array $tenantScopes = [],
    ) {}

    /**
     * Build from the legacy array format used by AttributeDiscovery.
     *
     * @param array<string, mixed> $route
     */
    public static function fromArray(array $route): self
    {
        $methods = array_values(array_filter(
            is_array($route['methods'] ?? null) ? $route['methods'] : [$route['method'] ?? 'GET'],
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));
        if ($methods === []) {
            $methods = ['GET'];
        }

        return new self(
            path: (string) ($route['path'] ?? ''),
            methods: $methods,
            name: is_string($route['name'] ?? null) ? $route['name'] : null,
            requestClass: (string) ($route['class'] ?? $route['requestClass'] ?? ''),
            responseClass: is_string($route['responseClass'] ?? null) ? $route['responseClass'] : null,
            handlers: array_values(array_filter((array) ($route['handlers'] ?? []), 'is_string')),
            type: (string) ($route['type'] ?? 'http_request'),
            produces: is_array($route['produces'] ?? null) ? array_values(array_filter($route['produces'], 'is_string')) : null,
            consumes: is_array($route['consumes'] ?? null) ? array_values(array_filter($route['consumes'], 'is_string')) : null,
            module: (string) ($route['module'] ?? ''),
            requirements: is_array($route['requirements'] ?? null) ? $route['requirements'] : [],
            defaults: is_array($route['defaults'] ?? null) ? $route['defaults'] : [],
            options: is_array($route['options'] ?? null) ? $route['options'] : [],
            tags: array_values(array_filter((array) ($route['tags'] ?? []), 'is_string')),
            public: (bool) ($route['public'] ?? false),
            tenantScopes: array_values(array_filter((array) ($route['tenantScopes'] ?? []), 'is_string')),
        );
    }

    /**
     * Convert back to the legacy array format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'methods' => $this->methods,
            'name' => $this->name,
            'class' => $this->requestClass,
            'responseClass' => $this->responseClass,
            'handlers' => $this->handlers,
            'type' => $this->type,
            'produces' => $this->produces,
            'consumes' => $this->consumes,
            'module' => $this->module,
            'requirements' => $this->requirements,
            'defaults' => $this->defaults,
            'options' => $this->options,
            'tags' => $this->tags,
            'public' => $this->public,
            'tenantScopes' => $this->tenantScopes,
        ];
    }
}
