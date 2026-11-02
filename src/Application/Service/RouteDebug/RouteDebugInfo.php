<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Service\RouteDebug;

/**
 * Everything routes:show says about one route: what --json prints (toArray()
 * is that shape, pinned by RoutesCommandsTest) and what the human view reads.
 */
final readonly class RouteDebugInfo
{
    /**
     * @param list<string>           $methods
     * @param list<RouteHandlerInfo> $handlers highest priority first
     * @param array<string, mixed>   $requirements
     * @param array<string, mixed>   $defaults
     * @param array<string, mixed>   $options
     * @param list<string>           $tags
     */
    public function __construct(
        public string $path,
        public array $methods,
        public ?string $name,
        public string $accessType,
        public ?string $type,
        public ClassLocation $payload,
        public ?RouteResourceInfo $resource,
        public array $handlers,
        public array $requirements,
        public array $defaults,
        public array $options,
        public array $tags,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'methods' => $this->methods,
            'name' => $this->name,
            'accessType' => $this->accessType,
            'type' => $this->type,
            'payload' => $this->payload->toArray(),
            'resource' => $this->resource?->toArray(),
            'handlers' => array_map(static fn (RouteHandlerInfo $h): array => $h->toArray(), $this->handlers),
            'requirements' => $this->requirements,
            'defaults' => $this->defaults,
            'options' => $this->options,
            'tags' => $this->tags,
        ];
    }
}
