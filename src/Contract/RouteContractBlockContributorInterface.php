<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * One Way Pattern — Phase 1: the contract-block contributor seam.
 *
 * Packages contribute NAMED blocks to the route contract document assembled
 * by {@see RouteContractAssemblerInterface} — the same `extensions`-bag
 * philosophy as {@see \Semitexa\Core\Discovery\ResolvedRouteMetadata}, but
 * for the served OPTIONS document. semitexa-api contributes the `collection`
 * block (sort/filter allowlists + pagination facts); platform-ui contributes
 * the `ui` presentation overlay in a later phase.
 *
 * Contributors register through the standard service-contract mechanism:
 * `#[AsService]` + `#[SatisfiesServiceContract(of:
 * RouteContractBlockContributorInterface::class)]`. The default assembler
 * enumerates ALL registered contributors via the container's additive
 * contract chain — contribution is additive, never overriding: blocks whose
 * keys collide with the core document keys (or with an earlier contributor's
 * block) are dropped.
 *
 * The response→resource link (`#[ProducesResourceObject]` /
 * `#[ProducesResourceCollection]`) is package vocabulary, so contributors
 * may also resolve the resource class backing a response class; core uses
 * the first non-null answer to build the `output` block from its own
 * ResourceMetadataRegistry.
 */
interface RouteContractBlockContributorInterface
{
    /**
     * Named blocks to add to the contract document, keyed by document key
     * (e.g. `['collection' => [...]]`). Return an empty array when the
     * package vocabulary has nothing to say about this route.
     *
     * @param class-string      $payloadClass
     * @param class-string|null $responseClass
     * @return array<string, array<string, mixed>>
     */
    public function contributeBlocks(string $payloadClass, ?string $responseClass): array;

    /**
     * Resolve the resource class whose metadata backs $responseClass, when
     * this package's vocabulary declares the link. Null when unknown.
     *
     * @param class-string|null $responseClass
     * @return class-string|null
     */
    public function resolveResourceClass(?string $responseClass): ?string;
}
