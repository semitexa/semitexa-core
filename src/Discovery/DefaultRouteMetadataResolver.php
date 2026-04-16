<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteMetadataResolverInterface;

/**
 * Default implementation of RouteMetadataResolverInterface.
 *
 * Converts the DiscoveredRoute DTO into a typed ResolvedRouteMetadata DTO.
 * No reflection work is performed here beyond reading the route properties.
 *
 * Packages that need to attach additional route-level metadata (e.g. semitexa-api
 * for ExternalApi / ApiVersion markers) should provide their own implementation
 * via #[SatisfiesServiceContract(of: RouteMetadataResolverInterface::class)] to enrich
 * the metadata with extension data.
 */
#[SatisfiesServiceContract(of: RouteMetadataResolverInterface::class)]
final class DefaultRouteMetadataResolver implements RouteMetadataResolverInterface
{
    public function resolve(DiscoveredRoute $route): ResolvedRouteMetadata
    {
        return new ResolvedRouteMetadata(
            path:          $route->path,
            name:          $route->name ?? '',
            methods:       $route->methods,
            requestClass:  $route->requestClass,
            responseClass: $route->responseClass ?? '',
            produces:      $route->produces,
            consumes:      $route->consumes,
            handlers:      $route->handlers,
            requirements:  $route->requirements,
            extensions:    $route->transport !== null ? ['transport' => $route->transport] : [],
        );
    }
}
