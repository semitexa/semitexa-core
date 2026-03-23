<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Request;
use Semitexa\Core\Response;

/**
 * Allows packages to decorate the final HTTP response for a resolved route.
 *
 * Core provides a no-op implementation. Higher-level packages such as
 * semitexa-api can override the binding to inject route-specific headers or
 * other compatibility-preserving response metadata without patching RouteExecutor.
 */
interface RouteResponseDecoratorInterface
{
    public function decorate(Response $response, Request $request, ResolvedRouteMetadata $metadata): Response;
}
