<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attribute\RouteExposure;
use Semitexa\Core\Exception\ConfigurationException;

/**
 * Which payload owns a route when several declare it.
 *
 * Discovery buckets every routable payload by the route it competes for
 * ({@see bucketKey()}) and then elects one winner per bucket by the override
 * chain ({@see select()}). Both steps are pure: no state, no container, so
 * they cannot leak between boots or coroutines.
 *
 * @phpstan-type RouteCandidate array{
 *   class: string,
 *   file: string,
 *   priority: int,
 *   overrides: ?string,
 *   resolved: array<string, mixed>,
 *   module: string,
 *   tenantScopes: list<string>
 * }
 */
final class RouteOverrideChain
{
    /**
     * The route a candidate competes for: path + normalized methods, except a
     * HUG route, whose path is always empty — it is addressed by name, so two
     * differently named HUG payloads are two routes, not an override pair.
     *
     * @param array<string, mixed> $resolved
     */
    public static function bucketKey(array $resolved): string
    {
        $methods = array_values(array_filter(
            is_array($resolved['methods'] ?? null) ? $resolved['methods'] : ['GET'],
            static fn (mixed $method): bool => is_string($method) && $method !== '',
        ));
        if ($methods === []) {
            $methods = ['GET'];
        }
        $methods = array_map('strtoupper', $methods);
        sort($methods);
        $methodKey = implode(',', $methods);

        if (($resolved['exposure'] ?? RouteExposure::Public) === RouteExposure::Hug) {
            return "hug\0" . (is_string($resolved['name'] ?? null) ? $resolved['name'] : '') . "\0" . $methodKey;
        }

        return (is_string($resolved['path'] ?? null) ? $resolved['path'] : '') . "\0" . $methodKey;
    }

    /**
     * Select the single Request for a route using override chain rules.
     * Only the current chain head can be overridden; otherwise throws.
     *
     * @param list<RouteCandidate> $candidates
     * @return RouteCandidate|null
     */
    public static function select(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $head = null;
        foreach ($candidates as $c) {
            $overrides = $c['overrides'];
            if ($overrides === null || $overrides === '') {
                if ($head !== null) {
                    $head = $c['priority'] > $head['priority'] ? $c : $head;
                } else {
                    $head = $c;
                }
                continue;
            }
            if ($head === null) {
                throw new ConfigurationException(
                    "Request {$c['class']} declares overrides of {$overrides}, but there is no request for this route to override. " .
                    "Remove the overrides attribute (registry is the single source of truth; registry payloads extend module base)."
                );
            }
            $headClass = $head['class'];
            if ($overrides !== $headClass) {
                throw new ConfigurationException(
                    "Request override chain violation: {$c['class']} tries to override {$overrides}, but the current head for this route is {$headClass}. " .
                    "You can only override the current head. Use overrides: {$headClass}::class to extend the chain."
                );
            }
            $head = $c;
        }
        return $head;
    }
}
