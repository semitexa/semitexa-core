<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Discovery\ResolvedRouteMetadata;
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

}
