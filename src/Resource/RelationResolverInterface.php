<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

/**
 * Phase 6b: contract for lazy relation resolvers.
 *
 * A resolver loads a single declared relation across many parents in
 * one batched call. The future Phase 6d `ResourceExpansionPipeline`
 * collects parents that share a `(field, target_class, resolver_class)`
 * tuple and dispatches one `resolveBatch()` per bucket.
 *
 * Phase 6b only ships the contract — there is no runtime caller yet.
 *
 * Implementations must:
 *   - be `#[AsService]` so the readonly container can resolve them per
 *     worker;
 *   - be stateless across requests (no per-request fields);
 *   - not depend on `Request`, on a specific persistence layer, on
 *     OpenAPI, or on any GraphQL-specific concept;
 *   - return a map keyed by `ResourceIdentity::urn()`. Missing keys
 *     mean "no related entity for this parent" and are wrapped by the
 *     pipeline as link-form refs at assemble time.
 *
 * The resolver returns raw `ResourceObjectInterface` values (or lists
 * of them). The pipeline owns `ResourceRef::embed()` /
 * `ResourceRefList::embed()` envelope construction; resolvers must
 * never wrap their own return values into envelopes.
 */
interface RelationResolverInterface
{
    /**
     * @param list<ResourceIdentity> $parents
     *   identities of the parent DTOs that requested this relation
     * @param RenderContext $ctx
     *   profile-neutral request context (`includes`, `profile`,
     *   `baseUrl`, `maxDepth`)
     *
     * @return array<string, ResourceObjectInterface|list<ResourceObjectInterface>|null>
     *   keyed by `ResourceIdentity::urn()`; value is:
     *     - a single resolved DTO for `ResourceRef` relations,
     *     - a list of DTOs for `ResourceRefList` relations,
     *     - `null` when the parent has no related entity.
     */
    public function resolveBatch(array $parents, RenderContext $ctx): array;
}
