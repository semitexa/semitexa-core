<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteInspectionRegistryInterface;
use Semitexa\Core\Contract\RouteMetadataResolverInterface;

/**
 * Default implementation of RouteInspectionRegistryInterface.
 *
 * Delegates enumeration to AttributeDiscovery (which maintains a worker-scoped
 * cache) and delegates DTO construction to the registered RouteMetadataResolverInterface
 * so that extension metadata added by packages such as semitexa-api is included.
 *
 * Worker-scoped: this class is registered as a readonly singleton (per Swoole
 * worker), so discovery work is paid only once.
 */
#[SatisfiesServiceContract(of: RouteInspectionRegistryInterface::class)]
final class DefaultRouteInspectionRegistry implements RouteInspectionRegistryInterface
{
    #[InjectAsReadonly]
    protected RouteMetadataResolverInterface $metadataResolver;

    /** @var list<ResolvedRouteMetadata>|null Worker-scoped cache after first call */
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        AttributeDiscovery::initialize();

        $resolved = [];
        foreach (AttributeDiscovery::getRoutes() as $route) {
            $resolved[] = $this->metadataResolver->resolve($route);
        }

        $this->cache = $resolved;
        return $this->cache;
    }

    public function findByPath(string $path, string $method): ?ResolvedRouteMetadata
    {
        foreach ($this->all() as $metadata) {
            if ($metadata->path === $path && in_array($method, $metadata->methods, true)) {
                return $metadata;
            }
        }
        return null;
    }

    public function findByName(string $name): ?ResolvedRouteMetadata
    {
        foreach ($this->all() as $metadata) {
            if ($metadata->name === $name) {
                return $metadata;
            }
        }
        return null;
    }
}
