<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Discovery\ResolvedRouteMetadata;

/**
 * Converts an internally-discovered route array into a typed, immutable
 * ResolvedRouteMetadata DTO.
 *
 * Core registers DefaultRouteMetadataResolver as the fallback.
 * Packages such as semitexa-api override this binding via
 * #[SatisfiesServiceContract(of: RouteMetadataResolverInterface::class)] to enrich
 * the metadata with external API markers, version info, and other extension data
 * — without touching AttributeDiscovery internals.
 */
interface RouteMetadataResolverInterface
{
    /**
     * @param array<string,mixed> $route Discovery-enriched route array from AttributeDiscovery
     */
    public function resolve(array $route): ResolvedRouteMetadata;
}
