<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteMetadataResolverInterface;

/**
 * Default implementation of RouteMetadataResolverInterface.
 *
 * Converts the internally-discovered route arrays produced by AttributeDiscovery
 * into typed ResolvedRouteMetadata DTOs.  No reflection work is performed here
 * beyond reading the already-enriched route array; discovery caches in
 * AttributeDiscovery are the source of truth.
 *
 * Packages that need to attach additional route-level metadata (e.g. semitexa-api
 * for ExternalApi / ApiVersion markers) should provide their own implementation
 * via #[SatisfiesServiceContract(of: RouteMetadataResolverInterface::class)].
 */
#[SatisfiesServiceContract(of: RouteMetadataResolverInterface::class)]
final class DefaultRouteMetadataResolver implements RouteMetadataResolverInterface
{
    public function resolve(array $route): ResolvedRouteMetadata
    {
        $methods = $route['methods'] ?? (isset($route['method']) ? [$route['method']] : ['GET']);

        return new ResolvedRouteMetadata(
            path:          $route['path'] ?? '',
            name:          $route['name'] ?? '',
            methods:       $methods,
            requestClass:  $route['class'] ?? '',
            responseClass: $route['responseClass'] ?? '',
            produces:      $route['produces'] ?? null,
            consumes:      $route['consumes'] ?? null,
            handlers:      $route['handlers'] ?? [],
            requirements:  $route['requirements'] ?? [],
            extensions:    [],
        );
    }
}
