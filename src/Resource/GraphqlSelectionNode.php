<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

/**
 * Phase 5c: minimal AST node for the bounded GraphQL selection parser.
 *
 * Each node carries a field name and a list of nested children. The
 * implicit `<root>` node wraps the root field declared by the client
 * (e.g. `customer`). Scalars are leaf nodes (no children); relations
 * may have children when the client wrote a sub-selection.
 *
 * Order is preserved as written by the client; the translator sorts
 * the produced IncludeSet tokens deterministically downstream.
 */
final class GraphqlSelectionNode
{
    /** @var list<GraphqlSelectionNode> */
    private array $children = [];

    public function __construct(public readonly string $name)
    {
    }

    public function addChild(GraphqlSelectionNode $child): void
    {
        $this->children[] = $child;
    }

    /** @return list<GraphqlSelectionNode> */
    public function children(): array
    {
        return $this->children;
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * Returns the single child of an `<root>` node — i.e. the root field
     * declared by the client. Throws if the root has zero or multiple
     * children, since Phase 5c targets one root field per query.
     */
    public function singleRootField(): GraphqlSelectionNode
    {
        $count = count($this->children);
        if ($count === 0) {
            throw new \RuntimeException('GraphQL query has no root field selection.');
        }
        if ($count > 1) {
            throw new \RuntimeException(sprintf(
                'GraphQL query has %d root fields; Phase 5c expects exactly one.',
                $count,
            ));
        }
        return $this->children[0];
    }
}
