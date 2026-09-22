<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Resource\AcceptHeaderResolver;
use Semitexa\Core\Resource\RenderProfile;

/**
 * Labels a body a resource already produced with what the resource IS.
 *
 * The type comes from the profile the route served, never from the body, and
 * only when the resource has not declared one. That distinction is safe at
 * render time because a ResourceResponse is born with no headers at all: the
 * bare `text/html` a client sees on an unlabelled response is Swoole's
 * default, added at emit time, after the renderer. So a Content-Type the
 * resource carries was set by its own class or its handler — a declaration —
 * and JsonLdResourceResponse's `application/ld+json`, or a handler's
 * `text/csv`, is kept rather than flattened to the profile's type.
 *
 * MEASURED 2026-09-22 across every parameterless GET route x four Accept
 * headers: 21 JSON bodies on 7 routes went out as text/html, and this changed
 * exactly those 21 cells and no other.
 */
final class DataProfileLabel
{
    public static function apply(object $resDto, DiscoveredRoute $route): object
    {
        $profile = self::servedProfile($resDto, $route);
        if ($profile === null || !self::hasBody($resDto) || self::declaresContentType($resDto)) {
            return $resDto;
        }

        $mediaType = AcceptHeaderResolver::profileMediaType($profile);
        if ($mediaType !== null && method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', $mediaType);
        }

        return $resDto;
    }

    /**
     * The data profile this response was built for, or null for a page.
     *
     * A multi-profile route names its class per profile in
     * `responsesByProfile`, and CrossProfileDispatcher has already swapped the
     * class in; which entry the resource is IS the profile served. A
     * single-profile route states it outright. Html — and a route that states
     * nothing — is a page, and is left to the layout path.
     */
    public static function servedProfile(object $resDto, DiscoveredRoute $route): ?RenderProfile
    {
        $served = null;

        if ($route->responsesByProfile !== null && $route->responsesByProfile !== []) {
            // Exact class first: the instance may be a generated subclass
            // carrying resource parts, and one profile's class may extend
            // another's.
            $key = array_search($resDto::class, $route->responsesByProfile, true);
            if ($key === false) {
                foreach ($route->responsesByProfile as $profileKey => $class) {
                    if (is_string($class) && $resDto instanceof $class) {
                        $key = $profileKey;
                        break;
                    }
                }
            }
            $served = is_string($key) ? RenderProfile::tryFrom($key) : null;
        }

        if ($served === null) {
            $declared = $route->renderProfile;
            if ($declared instanceof RenderProfile) {
                $served = $declared;
            } elseif (is_array($declared) && count($declared) === 1) {
                $only = reset($declared);
                $served = $only instanceof RenderProfile ? $only : null;
            }
        }

        return $served === RenderProfile::Html ? null : $served;
    }

    private static function hasBody(object $resDto): bool
    {
        if (!method_exists($resDto, 'getContent')) {
            return false;
        }

        $content = $resDto->getContent();

        return is_string($content) && $content !== '';
    }

    private static function declaresContentType(object $resDto): bool
    {
        if (!method_exists($resDto, 'getHeaders')) {
            return false;
        }

        $headers = $resDto->getHeaders();
        if (!is_array($headers)) {
            return false;
        }

        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strcasecmp($name, 'Content-Type') === 0) {
                return true;
            }
        }

        return false;
    }
}
