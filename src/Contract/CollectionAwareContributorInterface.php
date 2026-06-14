<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Optional capability a {@see RouteContractBlockContributorInterface} MAY also
 * implement to tell the assembler whether a response class represents a
 * COLLECTION of resources — independent of whether the contributor emits a
 * rich `collection` block (sort/filter/pagination).
 *
 * This exists because a bare collection (e.g. a response carrying only
 * `#[ProducesResourceCollection]` with no query-capability attributes) emits
 * no `collection` block, so {@see RouteContract::collectionBlock()} is a
 * "rich collection" signal, NOT a reliable "is-a-list" one. Consumers that
 * only need cardinality (e.g. GraphQL deriving a `[T]` list type) read
 * {@see RouteContract::$isCollection} instead.
 *
 * Kept as a SEPARATE interface, checked via `instanceof`, so adding the
 * capability never breaks contributors that don't implement it.
 */
interface CollectionAwareContributorInterface
{
    /**
     * True when this package's vocabulary marks $responseClass as a collection
     * of resources. False (the safe default) when it's a single resource,
     * unknown, or this contributor has no opinion.
     *
     * @param class-string|null $responseClass
     */
    public function resolvesCollection(?string $responseClass): bool;
}
