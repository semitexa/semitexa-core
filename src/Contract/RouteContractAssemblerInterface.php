<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Http\RouteContract;

/**
 * One Way Pattern — Phase 1 (G1 keystone): assembles the full field-level
 * route contract for a single route by JOINING the two metadata halves that
 * already exist — the input side (PayloadMetadataReflector's OPTIONS
 * document) and the output side (ResourceMetadataRegistry's
 * ResourceObjectMetadata) — plus any named blocks contributed by packages
 * through {@see RouteContractBlockContributorInterface} (semitexa-api
 * contributes `collection`; platform-ui contributes `ui` in a later phase).
 *
 * Mirrors the proven {@see RouteMetadataResolverInterface} container-override
 * pattern: core ships the interface and a default implementation
 * ({@see \Semitexa\Core\Http\DefaultRouteContractAssembler}); packages extend
 * via the contributor seam rather than replacing the assembler.
 *
 * Consumers: {@see \Semitexa\Core\Pipeline\OptionsMetadataHandler} (serves
 * the document over OPTIONS with an ETag) and the semitexa-api OpenAPI route
 * generator (reads the collection facts — both shapes, one source).
 */
interface RouteContractAssemblerInterface
{
    /**
     * Assemble the contract for the route served by $payloadClass.
     *
     * @param class-string      $payloadClass  the route's payload (request DTO) class
     * @param class-string|null $responseClass the route's response class (responseWith),
     *                                         null when the route declares none
     */
    public function assemble(string $payloadClass, ?string $responseClass): RouteContract;
}
