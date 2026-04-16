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
     * @param list<array<string, mixed>> $handlers
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
        public ?string $transport,
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
        /** @var string $path */
        $path = is_string($route['path'] ?? null) ? $route['path'] : '';
        /** @var string|null $name */
        $name = is_string($route['name'] ?? null) ? $route['name'] : null;
        /** @var string $requestClass */
        $requestClass = '';
        if (is_string($route['class'] ?? null)) {
            $requestClass = $route['class'];
        } elseif (is_string($route['requestClass'] ?? null)) {
            $requestClass = $route['requestClass'];
        }
        /** @var string|null $responseClass */
        $responseClass = is_string($route['responseClass'] ?? null) ? $route['responseClass'] : null;
        /** @var list<array<string, mixed>> $handlers */
        $handlers = self::normalizeHandlerList($route['handlers'] ?? []);
        /** @var string $type */
        $type = is_string($route['type'] ?? null) ? $route['type'] : 'http_request';
        /** @var list<string> $produces */
        $produces = self::normalizeStringList($route['produces'] ?? null);
        /** @var list<string> $consumes */
        $consumes = self::normalizeStringList($route['consumes'] ?? null);
        /** @var string|null $transport */
        $transport = is_string($route['transport'] ?? null) ? $route['transport'] : null;
        /** @var string $module */
        $module = is_string($route['module'] ?? null) ? $route['module'] : '';
        /** @var array<string, string> $requirements */
        $requirements = is_array($route['requirements'] ?? null) ? self::normalizeStringMap($route['requirements']) : [];
        /** @var array<string, mixed> $defaults */
        $defaults = is_array($route['defaults'] ?? null) ? self::normalizeMixedMap($route['defaults']) : [];
        /** @var array<string, mixed> $options */
        $options = is_array($route['options'] ?? null) ? self::normalizeMixedMap($route['options']) : [];
        /** @var list<string> $tags */
        $tags = self::normalizeStringList($route['tags'] ?? []);
        /** @var list<string> $tenantScopes */
        $tenantScopes = self::normalizeStringList($route['tenantScopes'] ?? []);

        $methods = array_values(array_filter(
            is_array($route['methods'] ?? null) ? $route['methods'] : [$route['method'] ?? 'GET'],
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));
        if ($methods === []) {
            $methods = ['GET'];
        }

        return new self(
            path: $path,
            methods: $methods,
            name: $name,
            requestClass: $requestClass,
            responseClass: $responseClass,
            handlers: $handlers,
            type: $type,
            transport: $transport,
            produces: $produces,
            consumes: $consumes,
            module: $module,
            requirements: $requirements,
            defaults: $defaults,
            options: $options,
            tags: $tags,
            public: (bool) ($route['public'] ?? false),
            tenantScopes: $tenantScopes,
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
            'transport' => $this->transport,
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

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function normalizeHandlerList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $handlers = [];
        foreach ($value as $handler) {
            if (is_array($handler)) {
                /** @var array<string, mixed> $normalizedHandler */
                $normalizedHandler = $handler;
                $handlers[] = $normalizedHandler;
            }
        }

        return $handlers;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, string>
     */
    private static function normalizeStringMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function normalizeMixedMap(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
