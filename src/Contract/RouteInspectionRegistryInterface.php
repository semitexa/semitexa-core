<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Discovery\ResolvedRouteMetadata;

/**
 * Provides read-only enumeration of all discovered routes as typed metadata objects.
 *
 * This contract exists so that external packages — including semitexa-api for OpenAPI
 * export and semitexa-webhooks for route introspection — can enumerate routes through
 * a stable seam without reading AttributeDiscovery internal arrays directly.
 *
 * Core registers DefaultRouteInspectionRegistry as the default implementation,
 * backed by the existing discovery caches.
 */
interface RouteInspectionRegistryInterface
{
    /**
     * Return metadata for every discovered route.
     *
     * @return list<ResolvedRouteMetadata>
     */
    public function all(): array;

    /**
     * Find the first route that matches the given path and HTTP method.
     * Returns null when no match is found.
     */
    public function findByPath(string $path, string $method): ?ResolvedRouteMetadata;

    /**
     * Find a route by its named identifier (the `name:` value on AsPayload).
     * Returns null when the name is not registered.
     */
    public function findByName(string $name): ?ResolvedRouteMetadata;
}
