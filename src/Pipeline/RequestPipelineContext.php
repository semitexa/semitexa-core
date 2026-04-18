<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Request;

/**
 * Single mutable context object shared across all pipeline phases.
 *
 * Phases execute sequentially in one request scope: Access needs auth result,
 * Handle needs access result. A single context that accumulates state is the natural model.
 *
 * $resolvedMetadata carries the typed route metadata produced by RouteMetadataResolverInterface.
 * Pipeline listeners and packages can read extension flags (e.g. 'external_api') from it
 * without touching raw route arrays or discovery internals.
 */
class RequestPipelineContext
{
    public ?object $resourceDto;
    public ?AuthResult $authResult = null;
    public ?string $lastHandlerClass = null;

    public function __construct(
        public readonly object $requestDto,
        public readonly DiscoveredRoute $route,
        public readonly Request $request,
        ?object $resourceDto = null,
        /** Set by Application when auth package is present so AuthCheckListener can delegate to AuthBootstrapperInterface. */
        public readonly ?AuthBootstrapperInterface $authBootstrapper = null,
        /** Typed route metadata resolved at the start of RouteExecutor::execute(). */
        public readonly ?ResolvedRouteMetadata $resolvedMetadata = null,
    ) {
        $this->resourceDto = $resourceDto;
    }
}
