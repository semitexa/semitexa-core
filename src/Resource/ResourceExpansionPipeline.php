<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Exception\InvalidResolvedRelationException;
use Semitexa\Core\Resource\Exception\InvalidResourceResolverException;
use Semitexa\Core\Resource\Exception\NestedIncludeDepthExceededException;
use Semitexa\Core\Resource\Exception\ResourceResolverNotFoundException;
use Semitexa\Core\Resource\Memo\ResolverMemoStore;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * Phase 6d/6e/6f/6g: the runtime skeleton of the lazy resolver pipeline.
 *
 * The pipeline runs **once per response**, between
 * `IncludeValidator::validate()` and `Renderer::render()`. It walks
 * the parent Resource DTO(s) metadata, picks out relation fields
 * whose `?include=` token was requested AND whose `#[ResolveWith]`
 * resolver class is set, container-resolves the resolver, and calls
 * `RelationResolverInterface::resolveBatch()` once per resolver-backed
 * relation **bucket**. The resulting values land in a fresh
 * `ResolvedResourceGraph` overlay.
 *
 * Phase 6d/6e/6f/6g boundaries (deliberate):
 *
 *   - **Phase 6f: list-parent batching.** When more than one parent
 *     identity needs the same resolver-backed relation, the pipeline
 *     groups them into a bucket keyed by
 *     `(parentClass, relationField, targetClass, resolverClass)` and
 *     dispatches exactly one `resolveBatch()` per bucket. Single-parent
 *     callers are routed through the same code path with a one-entry
 *     bucket — output is byte-identical to Phase 6e.
 *   - **Phase 6g: nested expansion.** A second resolver pass runs for
 *     dotted include tokens (e.g. `profile.preferences`). The first
 *     pass resolves the parent relation; for every `ResourceObject` it
 *     produced, the pipeline collects identities and dispatches a
 *     second bucket per nested resolver-backed leaf field. Strict
 *     depth cap: **two segments total** (one nested level beyond the
 *     root). Deeper dotted tokens raise
 *     `NestedIncludeDepthExceededException`.
 *   - **Handler-provided includes are untouched.** Their relations
 *     stay on the eager-handler path; the pipeline ignores them.
 *   - **No cycles, no recursion.** The pipeline does not call itself.
 *
 * Pure-ish: the pipeline calls into the container exactly once per
 * resolver-backed relation per request and into a resolver exactly
 * once per bucket per request. It performs no DB, ORM, HTTP,
 * IriBuilder, or renderer access itself; resolvers may do those
 * things, but the pipeline's surface stays profile-neutral.
 */
#[AsService]
final class ResourceExpansionPipeline
{
    /**
     * Phase 6g cap. Aligned with `GraphqlSelectionToIncludeSet::MAX_DEPTH`
     * (which counts nested levels beyond the root). A two-segment
     * dotted token like `profile.preferences` is permitted; three or
     * more segments raise `NestedIncludeDepthExceededException`.
     */
    public const MAX_NESTED_DEPTH = 1;

    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /**
     * Test factory bypasses property injection. Tests pass a
     * minimal `ContainerInterface` (e.g., a small array-backed stub)
     * that knows how to produce the resolver instances under test.
     */
    public static function forTesting(
        ResourceMetadataRegistry $registry,
        ContainerInterface $container,
    ): self {
        $p = new self();
        $p->registry  = $registry;
        $p->container = $container;
        return $p;
    }

    /**
     * Phase 6d entry point: expand a single root Resource DTO.
     *
     * Behaviour preserved exactly: an empty include set still returns
     * a root-only overlay; a missing id field still returns a
     * root-only overlay. The resolver-backed paths are routed through
     * `expandMany()` so a one-parent request walks the same bucket
     * machinery as a multi-parent request, with a one-entry bucket.
     */
    public function expand(
        ResourceObjectInterface $root,
        IncludeSet $includes,
        RenderContext $context,
    ): ResolvedResourceGraph {
        if ($includes->isEmpty()) {
            return ResolvedResourceGraph::withRootOnly($root, $includes);
        }

        $rootMetadata = $this->registry->require($root::class);
        if ($rootMetadata->idField === null) {
            // No identity, no addressable resolution map. Phase 6d
            // requires a stable parent identity to key resolved data.
            // Fall through to a root-only overlay.
            return ResolvedResourceGraph::withRootOnly($root, $includes);
        }

        return $this->expandMany([$root], $includes, $context);
    }

    /**
     * Phase 6f: expand a list of root Resource DTOs. The pipeline
     * groups parents into per-relation buckets and calls
     * `resolveBatch()` once per bucket.
     *
     * Empty list → empty-collection graph. One parent → behaviourally
     * identical to `expand()`. Multiple parents → exactly one
     * `resolveBatch()` per resolver-backed bucket, regardless of how
     * many parents land in the bucket.
     *
     * Phase 6g: after the top-level pass, dispatch a second pass for
     * dotted include tokens. The second pass uses the resolved values
     * from the first pass as its parents and runs at most one nested
     * level.
     *
     * @param list<ResourceObjectInterface> $roots
     */
    public function expandMany(
        array $roots,
        IncludeSet $includes,
        RenderContext $context,
    ): ResolvedResourceGraph {
        if (!array_is_list($roots)) {
            throw new \InvalidArgumentException(
                'ResourceExpansionPipeline::expandMany() requires a list of ResourceObjectInterface; '
                . 'got an associative array.',
            );
        }
        foreach ($roots as $i => $r) {
            if (!$r instanceof ResourceObjectInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'ResourceExpansionPipeline::expandMany(): parent at index %d is %s, not ResourceObjectInterface.',
                    $i,
                    get_debug_type($r),
                ));
            }
        }

        if ($roots === []) {
            return ResolvedResourceGraph::emptyCollection($includes);
        }
        if ($includes->isEmpty()) {
            return new ResolvedResourceGraph($roots, $includes, []);
        }

        $this->guardMaxDepth($includes);

        // Phase 7 (post-v1): expansion-scoped resolver memo. Created
        // here, discarded when this method returns. Two separate
        // expand()/expandMany() calls do not share memo state — a
        // deliberate Swoole safety choice. Inside one expansion the
        // memo collapses duplicate resolver demand caused by
        // duplicate parent identities, overlapping include tokens
        // (e.g. `profile` + `profile.preferences`), and any future
        // code path that re-asks for the same `(resolver, parent,
        // field)` triple.
        $memo = new ResolverMemoStore();

        // ---- Top-level pass --------------------------------------
        // Bucket parents by (parentClass, fieldName, target, resolver)
        // and run one resolveBatch() per bucket. Each bucket entry
        // also remembers which top-level field was requested with a
        // dotted (nested) token so the nested pass can target the
        // right children.
        /** @var array<string, array{
         *   parentClass: class-string,
         *   parentMetadata: ResourceObjectMetadata,
         *   field: ResourceFieldMetadata,
         *   parents: list<array{root: ResourceObjectInterface, identity: ResourceIdentity}>,
         * }> $topBuckets
         */
        $topBuckets = [];

        foreach ($roots as $root) {
            $rootMetadata = $this->registry->require($root::class);
            if ($rootMetadata->idField === null) {
                continue;
            }
            $rootIdentity = $this->extractIdentity(
                $root,
                $rootMetadata->idField,
                $rootMetadata->type,
            );

            foreach ($rootMetadata->fields as $field) {
                if (!$field->isRelation() || $field->resolverClass === null) {
                    continue;
                }
                if (!$this->isRequested($field, $includes)) {
                    continue;
                }

                $bucketKey = $this->bucketKey($rootMetadata->class, $field);
                if (!isset($topBuckets[$bucketKey])) {
                    $topBuckets[$bucketKey] = [
                        'parentClass'    => $rootMetadata->class,
                        'parentMetadata' => $rootMetadata,
                        'field'          => $field,
                        'parents'        => [],
                    ];
                }
                $topBuckets[$bucketKey]['parents'][] = [
                    'root'     => $root,
                    'identity' => $rootIdentity,
                ];
            }
        }

        $resolved = [];
        // Captured per top-level bucket so the nested pass can pick
        // up resolved children without re-walking the overlay.
        /** @var list<array{
         *   field: ResourceFieldMetadata,
         *   parentMetadata: ResourceObjectMetadata,
         *   parents: list<array{root: ResourceObjectInterface, identity: ResourceIdentity, resolved: mixed}>,
         * }> $resolvedSlices
         */
        $resolvedSlices = [];

        foreach ($topBuckets as $bucket) {
            $field          = $bucket['field'];
            $bucketParents  = $bucket['parents'];
            $parentClass    = $bucket['parentMetadata']->class;

            // Phase 7 split: parents the memo can answer right now
            // versus parents that still need a resolveBatch() call.
            // Skipping the resolver entirely when every parent is
            // memoised is the whole point of this pass.
            $unresolvedIdentities = [];
            $seenUrnsForBatch     = [];
            foreach ($bucketParents as $entry) {
                $memoKey = ResolverMemoStore::keyFromField($field, $entry['identity'], $parentClass);
                if ($memo->has($memoKey)) {
                    continue;
                }
                $urn = $entry['identity']->urn();
                if (isset($seenUrnsForBatch[$urn])) {
                    // Duplicate parent identity inside one bucket —
                    // ask the resolver for it once even when the
                    // memo doesn't have it yet.
                    continue;
                }
                $seenUrnsForBatch[$urn] = true;
                $unresolvedIdentities[] = $entry['identity'];
            }

            $batch = null;
            if ($unresolvedIdentities !== []) {
                $resolver = $this->makeResolver($field);
                $batch    = $resolver->resolveBatch($unresolvedIdentities, $context);
                $this->validateBatchShape($batch, $field);

                // Stamp the memo with each newly resolved value
                // BEFORE we write into the overlay, so a subsequent
                // bucket that touches the same `(resolver, parent,
                // field)` (or the nested pass) hits the memo.
                foreach ($unresolvedIdentities as $identity) {
                    $value   = $this->extractValueFor($batch, $identity, $field);
                    $memoKey = ResolverMemoStore::keyFromField($field, $identity, $parentClass);
                    $memo->set($memoKey, $value);
                }
            }

            // Walk every bucket parent (including duplicates and
            // already-memoised ones) and write the slot value into
            // the graph + the slice. Duplicate parent identities
            // therefore land at the same overlay key; the renderer
            // looks values up by `(parent, field)`, so duplicates
            // collapse to one slot.
            $sliceParents = [];
            foreach ($bucketParents as $entry) {
                $memoKey = ResolverMemoStore::keyFromField($field, $entry['identity'], $parentClass);
                $value   = $memo->get($memoKey);
                $key     = ResolvedResourceGraph::formatKey($entry['identity']->urn(), $field->name);
                $resolved[$key] = $value;

                $sliceParents[] = [
                    'root'     => $entry['root'],
                    'identity' => $entry['identity'],
                    'resolved' => $value,
                ];
            }

            $resolvedSlices[] = [
                'field'          => $field,
                'parentMetadata' => $bucket['parentMetadata'],
                'parents'        => $sliceParents,
            ];
        }

        // ---- Nested pass (Phase 6g) ------------------------------
        // For every top-level bucket whose field has a nested include
        // group (e.g. `profile.preferences`), gather the resolved
        // child DTOs, build a fresh bucket per nested resolver-backed
        // leaf field, and dispatch one resolveBatch() per child
        // bucket. Children whose top-level resolver returned `null`
        // are skipped — there is no parent to expand.
        foreach ($resolvedSlices as $slice) {
            $parentField   = $slice['field'];
            $nestedTokens  = $includes->nested($parentField->include ?? '');
            if ($nestedTokens->isEmpty()) {
                continue;
            }

            $childMetadata = $this->resolveTargetMetadata($parentField);
            if ($childMetadata === null) {
                // No metadata for the target — Phase 6g cannot expand
                // a child whose class isn't registered. The validator
                // is supposed to catch this earlier; if not, fail
                // softly by skipping rather than crashing.
                continue;
            }

            // Bucket children by (childClass, fieldName, target,
            // resolver). The bucket key is namespaced by parentField
            // so two parents that resolve to different child classes
            // never collide.
            /** @var array<string, array{
             *   field: ResourceFieldMetadata,
             *   identities: list<ResourceIdentity>,
             *   seenUrns: array<string, true>,
             * }> $nestedBuckets
             */
            $nestedBuckets = [];

            foreach ($slice['parents'] as $parent) {
                $childDtos = $this->collectChildDtosForNestedExpansion(
                    $parent['resolved'],
                    $parentField,
                );
                foreach ($childDtos as $childDto) {
                    $childIdentity = $this->extractIdentity(
                        $childDto,
                        (string) $childMetadata->idField,
                        $childMetadata->type,
                    );

                    foreach ($childMetadata->fields as $nestedField) {
                        if (!$nestedField->isRelation() || $nestedField->resolverClass === null) {
                            continue;
                        }
                        if (!$this->isRequested($nestedField, $nestedTokens)) {
                            continue;
                        }

                        $nKey = $this->bucketKey($childMetadata->class, $nestedField);
                        if (!isset($nestedBuckets[$nKey])) {
                            $nestedBuckets[$nKey] = [
                                'field'      => $nestedField,
                                'identities' => [],
                                'seenUrns'   => [],
                            ];
                        }
                        $urn = $childIdentity->urn();
                        if (!isset($nestedBuckets[$nKey]['seenUrns'][$urn])) {
                            $nestedBuckets[$nKey]['identities'][]      = $childIdentity;
                            $nestedBuckets[$nKey]['seenUrns'][$urn]    = true;
                        }
                    }
                }
            }

            foreach ($nestedBuckets as $nestedBucket) {
                $nestedField  = $nestedBucket['field'];
                $nestedIdents = $nestedBucket['identities'];
                if ($nestedIdents === []) {
                    continue;
                }

                // Phase 7: same memo-aware split as the top-level
                // pass. The nested pass already deduplicates child
                // urns when bucketing, but the memo also short-
                // circuits across nested buckets that share a
                // `(resolver, child, field)` triple.
                $unresolved = [];
                foreach ($nestedIdents as $childIdentity) {
                    $memoKey = ResolverMemoStore::keyFromField(
                        $nestedField,
                        $childIdentity,
                        $childMetadata->class,
                    );
                    if (!$memo->has($memoKey)) {
                        $unresolved[] = $childIdentity;
                    }
                }

                if ($unresolved !== []) {
                    $resolver = $this->makeResolver($nestedField);
                    $batch    = $resolver->resolveBatch($unresolved, $context);
                    $this->validateBatchShape($batch, $nestedField);
                    foreach ($unresolved as $childIdentity) {
                        $value   = $this->extractValueFor($batch, $childIdentity, $nestedField);
                        $memoKey = ResolverMemoStore::keyFromField(
                            $nestedField,
                            $childIdentity,
                            $childMetadata->class,
                        );
                        $memo->set($memoKey, $value);
                    }
                }

                foreach ($nestedIdents as $childIdentity) {
                    $memoKey = ResolverMemoStore::keyFromField(
                        $nestedField,
                        $childIdentity,
                        $childMetadata->class,
                    );
                    $value   = $memo->get($memoKey);
                    $key     = ResolvedResourceGraph::formatKey($childIdentity->urn(), $nestedField->name);
                    $resolved[$key] = $value;
                }
            }
        }

        return new ResolvedResourceGraph($roots, $includes, $resolved);
    }

    /**
     * A relation field is "requested" if its include token is
     * present in the IncludeSet, OR if any token in the IncludeSet is
     * dotted under it (e.g. `profile.preferences` implies `profile`).
     * This is the parent-implication rule: a dotted token cannot be
     * satisfied without expanding its parent.
     */
    private function isRequested(ResourceFieldMetadata $field, IncludeSet $includes): bool
    {
        if ($field->include === null) {
            return false;
        }
        if ($includes->has($field->include)) {
            return true;
        }
        $needle = $field->include . '.';
        foreach ($includes->tokens as $token) {
            if (str_starts_with($token, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bucket key for `(parentClass, relationField, targetClass,
     * resolverClass)`. Two parents from the same Resource class that
     * request the same resolver-backed relation share a bucket and
     * fall into a single `resolveBatch()` call. Distinct parent
     * classes — even if they declare a relation with the same field
     * name — produce distinct buckets so each parent class's metadata
     * stays authoritative for shape validation.
     *
     * @param class-string $parentClass
     */
    private function bucketKey(string $parentClass, ResourceFieldMetadata $field): string
    {
        return implode('|', [
            $parentClass,
            $field->name,
            $field->target ?? '',
            $field->resolverClass ?? '',
        ]);
    }

    private function makeResolver(ResourceFieldMetadata $field): RelationResolverInterface
    {
        \assert($field->resolverClass !== null);
        $resolverClass = $field->resolverClass;

        try {
            $resolver = $this->container->get($resolverClass);
        } catch (NotFoundExceptionInterface | ContainerExceptionInterface $e) {
            throw new ResourceResolverNotFoundException(
                resolverClass: $resolverClass,
                relationName:  $field->name,
                previous:      $e,
            );
        }

        if (!$resolver instanceof RelationResolverInterface) {
            throw new InvalidResourceResolverException(
                resolverClass: $resolverClass,
                relationName:  $field->name,
                actualClass:   $resolver::class,
            );
        }

        return $resolver;
    }

    /**
     * Validate the resolver's batch return shape (top-level — must be
     * a string-keyed array).
     *
     * @param mixed $batch raw value returned by the resolver
     */
    private function validateBatchShape(mixed $batch, ResourceFieldMetadata $field): void
    {
        if (!is_array($batch)) {
            throw new InvalidResolvedRelationException(
                resolverClass: $field->resolverClass ?? '?',
                relationName:  $field->name,
                reason:        'resolveBatch() must return an array; got ' . get_debug_type($batch),
            );
        }

        foreach (array_keys($batch) as $key) {
            if (!is_string($key)) {
                throw new InvalidResolvedRelationException(
                    resolverClass: $field->resolverClass ?? '?',
                    relationName:  $field->name,
                    reason:        'returned map key must be a string urn; got ' . get_debug_type($key),
                );
            }
        }
    }

    /**
     * Pluck the value for a single parent from a validated batch
     * return map. Per-parent shape rules (Phase 6d / 6e):
     *
     *   - to-one relations: `ResourceObjectInterface|null`.
     *   - to-many relations: `list<ResourceObjectInterface>` (never
     *     `null`; an empty list expresses "no related entities").
     *   - missing parent key: treated as "absent" — `null` for
     *     to-one, empty list for to-many. This is the safe default
     *     for batch resolvers that legitimately return only the
     *     parents they have data for.
     *   - extra keys (not corresponding to any requested parent) are
     *     ignored.
     *
     * @param array<string, mixed> $batch
     */
    private function extractValueFor(
        array $batch,
        ResourceIdentity $parent,
        ResourceFieldMetadata $field,
    ): mixed {
        $urn       = $parent->urn();
        $hasParent = array_key_exists($urn, $batch);
        $value     = $hasParent ? $batch[$urn] : null;

        if ($field->isList()) {
            if (!$hasParent) {
                return [];
            }
            if ($value === null) {
                throw new InvalidResolvedRelationException(
                    resolverClass: $field->resolverClass ?? '?',
                    relationName:  $field->name,
                    reason:        'to-many relations cannot be null; return [] for "no related entities"',
                );
            }
            if (!is_array($value) || !array_is_list($value)) {
                throw new InvalidResolvedRelationException(
                    resolverClass: $field->resolverClass ?? '?',
                    relationName:  $field->name,
                    reason:        'to-many relation must be a list; got ' . get_debug_type($value),
                );
            }
            foreach ($value as $i => $item) {
                if (!$item instanceof ResourceObjectInterface) {
                    throw new InvalidResolvedRelationException(
                        resolverClass: $field->resolverClass ?? '?',
                        relationName:  $field->name,
                        reason:        sprintf('item at index %d is %s, not ResourceObjectInterface', $i, get_debug_type($item)),
                    );
                }
            }
            return $value;
        }

        if ($value === null) {
            return null;
        }
        if (!$value instanceof ResourceObjectInterface) {
            throw new InvalidResolvedRelationException(
                resolverClass: $field->resolverClass ?? '?',
                relationName:  $field->name,
                reason:        'to-one relation must be ResourceObjectInterface or null; got ' . get_debug_type($value),
            );
        }

        if ($field->target !== null && !$value instanceof $field->target) {
            throw new InvalidResolvedRelationException(
                resolverClass: $field->resolverClass ?? '?',
                relationName:  $field->name,
                reason:        sprintf('expected target type %s, got %s', $field->target, $value::class),
            );
        }

        return $value;
    }

    private function extractIdentity(
        ResourceObjectInterface $resource,
        string $idField,
        string $type,
    ): ResourceIdentity {
        $vars = get_object_vars($resource);
        $id   = $vars[$idField] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \LogicException(sprintf(
                'Cannot expand resource %s: id field "%s" did not yield a non-empty string.',
                $resource::class,
                $idField,
            ));
        }
        return new ResourceIdentity($type, $id);
    }

    /**
     * Phase 6g: enforce the nested-include depth cap. The pipeline
     * supports exactly one nested level beyond the root (`a.b`);
     * three or more segments are rejected before any resolver runs.
     */
    private function guardMaxDepth(IncludeSet $includes): void
    {
        foreach ($includes->tokens as $token) {
            $segments = substr_count($token, '.');
            if ($segments > self::MAX_NESTED_DEPTH) {
                throw new NestedIncludeDepthExceededException(
                    token:    $token,
                    maxDepth: self::MAX_NESTED_DEPTH,
                );
            }
        }
    }

    /**
     * Resolve the metadata for the target type of a relation field.
     * Mirrors `IncludeValidator::resolveTargetMetadata()`; for
     * unions the first registered target is used so the pipeline
     * stays deterministic. `null` means "no target metadata to walk
     * into" — the caller treats that as "no nested expansion
     * possible for this branch".
     */
    private function resolveTargetMetadata(ResourceFieldMetadata $field): ?ResourceObjectMetadata
    {
        if ($field->target !== null) {
            return $this->registry->get($field->target);
        }
        if ($field->unionTargets !== null && $field->unionTargets !== []) {
            return $this->registry->get($field->unionTargets[0]);
        }
        return null;
    }

    /**
     * Phase 6g: extract the child Resource DTOs that should feed the
     * nested expansion pass. Single resolver returns are wrapped in a
     * one-element list so the caller can iterate uniformly.
     *
     * `null` resolver returns produce an empty list — there is no
     * parent to nest under. To-many resolver returns of `[]`
     * similarly produce an empty list. To-many parent nested
     * expansion IS supported here (each list item becomes a child
     * parent); the design doc records the case as "supported but
     * primarily exercised on the to-one slice" — the canonical
     * exercised path is to-one (`profile.preferences`).
     *
     * @return list<ResourceObjectInterface>
     */
    private function collectChildDtosForNestedExpansion(
        mixed $resolved,
        ResourceFieldMetadata $parentField,
    ): array {
        if ($resolved === null) {
            return [];
        }
        if ($parentField->isList()) {
            if (!is_array($resolved)) {
                return [];
            }
            $out = [];
            foreach ($resolved as $item) {
                if ($item instanceof ResourceObjectInterface) {
                    $out[] = $item;
                }
            }
            return $out;
        }
        if ($resolved instanceof ResourceObjectInterface) {
            return [$resolved];
        }
        return [];
    }
}
