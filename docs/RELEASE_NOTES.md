# Release Notes

## Unreleased

### Breaking

- `RouteMetadataResolverInterface::resolve()` now accepts `Semitexa\Core\Discovery\DiscoveredRoute` instead of the legacy array route shape.
- External implementations should update their signature to `resolve(DiscoveredRoute $route): ResolvedRouteMetadata`.
- If you still build routes as arrays, convert them first with `DiscoveredRoute::fromArray($route)` before passing them to the resolver.

