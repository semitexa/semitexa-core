<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

/**
 * Phase 6d: immutable overlay produced by `ResourceExpansionPipeline`.
 * Pairs the original root Resource DTO(s) with a path-keyed map of
 * values resolved by registered `RelationResolverInterface`
 * implementations.
 *
 * The graph is **profile-neutral**. It does not depend on JSON,
 * JSON-LD, GraphQL, OpenAPI, ORM, DB, or HTTP types. Renderers read
 * resolved values via `lookup()` and treat a hit as if the relation
 * had been embedded by the handler. A miss falls through to the raw
 * value on the DTO (handler-eager path).
 *
 * The map is keyed by:
 *
 *     "{parent_urn}|{relation_name}"
 *
 * where `parent_urn = ResourceIdentity::urn()`. Composite keys keep
 * the same root DTO graph addressable by `(parent, field)` even when
 * a parent appears more than once in a list.
 *
 * Stored values are:
 *
 *   - `ResourceObjectInterface`  for to-one relations
 *   - `list<ResourceObjectInterface>` for to-many relations
 *   - `null` for to-one relations whose resolver said "no related entity"
 *
 * No mutation. No lazy I/O. No global state. Two `ResolvedResourceGraph`
 * instances built from the same inputs are interchangeable.
 *
 * Phase 6f: a graph may carry multiple root parents (collection
 * expansion). The legacy `$root` slot is kept for single-parent
 * compatibility and points at the first entry of `$roots` (or `null`
 * when the graph was built from an empty parent list). Renderers
 * never read `$root`; they look up `(parent, field)` slots, which
 * works identically for one-root, many-root, and zero-root graphs.
 */
final readonly class ResolvedResourceGraph
{
    /**
     * Phase 6f: every root carried by this graph. Single-parent
     * expansion produces a one-element list. The list preserves the
     * caller's ordering — bucket batching never reorders the parent
     * list it received.
     *
     * @var list<ResourceObjectInterface>
     */
    public array $roots;

    /**
     * Phase 6d compatibility slot: the first root, or `null` for an
     * empty-collection graph. Existing single-parent callers may
     * continue to read `$graph->root`; multi-root callers should
     * iterate `$roots` instead.
     */
    public ?ResourceObjectInterface $root;

    /**
     * @param ResourceObjectInterface|list<ResourceObjectInterface> $root
     *   single root (Phase 6d/6e) or list of roots (Phase 6f). An
     *   empty list is allowed; it yields a graph with no roots and no
     *   resolutions.
     * @param array<string, ResourceObjectInterface|list<ResourceObjectInterface>|null> $resolved
     *   keyed by `formatKey(parentUrn, fieldName)`
     */
    public function __construct(
        ResourceObjectInterface|array $root,
        public IncludeSet $includes,
        public array $resolved = [],
    ) {
        if (is_array($root)) {
            if (!array_is_list($root)) {
                throw new \InvalidArgumentException(
                    'ResolvedResourceGraph: roots must be a list of ResourceObjectInterface.',
                );
            }
            foreach ($root as $i => $r) {
                if (!$r instanceof ResourceObjectInterface) {
                    throw new \InvalidArgumentException(sprintf(
                        'ResolvedResourceGraph: root at index %d is %s, not ResourceObjectInterface.',
                        $i,
                        get_debug_type($r),
                    ));
                }
            }
            $this->roots = $root;
            $this->root  = $root[0] ?? null;
        } else {
            $this->roots = [$root];
            $this->root  = $root;
        }
    }

    /**
     * Read-only graph carrying no resolutions. Used by Response classes
     * when no resolver-backed include was requested, so renderers can
     * treat the graph as a uniform read surface.
     */
    public static function withRootOnly(ResourceObjectInterface $root, IncludeSet $includes): self
    {
        return new self($root, $includes, []);
    }

    /**
     * Phase 6f: graph for a collection-shaped expansion that yielded
     * no parents. Carries an empty `roots` list and no resolutions.
     * Renderers iterating `roots` produce zero items; callers reading
     * `$graph->root` on this instance see `null`.
     */
    public static function emptyCollection(IncludeSet $includes): self
    {
        return new self([], $includes, []);
    }

    /** Stable composite key. */
    public static function formatKey(string $parentUrn, string $fieldName): string
    {
        return $parentUrn . '|' . $fieldName;
    }

    public function has(ResourceIdentity $parent, string $fieldName): bool
    {
        return array_key_exists(self::formatKey($parent->urn(), $fieldName), $this->resolved);
    }

    /**
     * @return ResourceObjectInterface|list<ResourceObjectInterface>|null
     *   the resolved value, or `null` when the resolver said "absent".
     *   Callers must check `has()` first to disambiguate "no resolution"
     *   from "resolution returned null".
     */
    public function lookup(ResourceIdentity $parent, string $fieldName): mixed
    {
        return $this->resolved[self::formatKey($parent->urn(), $fieldName)] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->resolved === [];
    }
}
