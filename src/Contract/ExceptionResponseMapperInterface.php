<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

/**
 * Converts a caught Throwable into an HTTP error Response.
 *
 * Core registers ExceptionMapper as the default implementation.
 * Packages such as semitexa-api provide an alternative via
 * #[SatisfiesServiceContract(of: ExceptionResponseMapperInterface::class)] to
 * produce machine-facing error envelopes for routes marked as external API routes.
 * Non-external routes must preserve Core default semantics.
 */
interface ExceptionResponseMapperInterface
{
    /**
     * Map a throwable to an HTTP Response.
     *
     * Implementations receive the resolved route metadata so they can inspect
     * produces formats, extension flags such as 'external_api', and other
     * route-level contract information without touching discovery internals.
     */
    public function map(\Throwable $e, Request $request, ResolvedRouteMetadata $metadata): HttpResponse;

    /**
     * Return an instance bound to the given request-scoped ErrorRouteDispatcher.
     *
     * The dispatcher is only meaningful for HTML error-route rendering performed by
     * the Core mapper. Decorators that wrap another mapper must forward this call so
     * the HTML fallback path remains wired.
     *
     * Implementations are stateless and must return a clone rather than mutating $this.
     */
    public function withErrorRouteDispatcher(ErrorRouteDispatcher $errorRouteDispatcher): static;
}
