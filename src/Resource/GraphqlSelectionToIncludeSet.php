<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Exception\GraphqlSelectionDepthExceededException;
use Semitexa\Core\Resource\Exception\UnknownGraphqlFieldException;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * Phase 5c: translate a parsed GraphQL selection set into an `IncludeSet`
 * by walking `ResourceObjectMetadata`.
 *
 * Rules (closed for v1):
 *   - **Only relation fields** become include tokens. Scalars are skipped.
 *   - **Unknown fields** → `UnknownGraphqlFieldException` (HTTP 400).
 *   - **Scalar with sub-selection** (e.g. `name { id }`) → 400 with a
 *     clear "scalar field cannot have a selection set" reason.
 *   - **Non-expandable relation** selected for embedding → 400.
 *   - **Nested relation tokens** are emitted up to depth 1 (mirrors
 *     `IncludeTokenCollector::MAX_DEPTH`). Deeper nesting →
 *     `GraphqlSelectionDepthExceededException` (HTTP 400).
 *   - **Duplicate selections** collapse to one token.
 *   - **Output sort**: `sort(SORT_STRING)` — same rule as
 *     `IncludeTokenCollector` for byte-stable downstream comparison.
 *   - **Polymorphic union** targets walked one level via the first
 *     registered target — same conservative rule as
 *     `IncludeValidator` and `IncludeTokenCollector`.
 *
 * Pure: no IO, no DB, no Request access, no renderer / IriBuilder
 * invocation.
 */
#[AsService]
final class GraphqlSelectionToIncludeSet
{
    /** Maximum nesting depth allowed by Phase 5c (matches Phase 3c limit). */
    public const MAX_DEPTH = 1;

    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    public static function forTesting(ResourceMetadataRegistry $registry): self
    {
        $t = new self();
        $t->registry = $registry;
        return $t;
    }

    /**
     * Translate a single root field's selection (e.g. `customer { … }`)
     * against the root Resource DTO's metadata.
     *
     * The `$rootField` is the parsed root field — typically the result of
     * `GraphqlSelectionParser::parse(...)->singleRootField()`. The caller
     * is responsible for verifying that `$rootField->name` matches the
     * route's expected resource (or omitting that check if the dispatcher
     * already gates on Accept).
     */
    public function translate(GraphqlSelectionNode $rootField, ResourceObjectMetadata $rootMetadata): IncludeSet
    {
        $tokens = [];
        foreach ($rootField->children() as $childNode) {
            $this->walk($childNode, $rootMetadata, '', 0, $tokens);
        }

        $list = array_keys($tokens);
        sort($list, SORT_STRING);

        return new IncludeSet($list);
    }

    /**
     * @param array<string, true> $tokens accumulator
     */
    private function walk(
        GraphqlSelectionNode $node,
        ResourceObjectMetadata $contextMetadata,
        string $prefix,
        int $depth,
        array &$tokens,
    ): void {
        $field = $contextMetadata->getField($node->name);

        if ($field === null) {
            throw new UnknownGraphqlFieldException(
                fieldPath: $prefix === '' ? $node->name : $prefix . '.' . $node->name,
                resourceType: $contextMetadata->type,
            );
        }

        if (!$field->isRelation()) {
            // Scalar / embedded. Sub-selections on scalars are nonsense and
            // become a clear client error.
            if ($node->hasChildren()) {
                throw new UnknownGraphqlFieldException(
                    fieldPath: $prefix === '' ? $node->name : $prefix . '.' . $node->name,
                    resourceType: $contextMetadata->type,
                    reason: 'scalar field cannot have a selection set',
                );
            }
            // Plain scalar selection — does not contribute to IncludeSet.
            return;
        }

        // Relation. The client effectively selected an embedded relation —
        // it must therefore be expandable, otherwise the runtime
        // IncludeValidator would reject it later anyway. Surface the
        // error here with a more specific message.
        if (!$field->expandable) {
            throw new UnknownGraphqlFieldException(
                fieldPath: $prefix === '' ? $node->name : $prefix . '.' . $node->name,
                resourceType: $contextMetadata->type,
                reason: 'relation is not expandable',
            );
        }

        $token = $field->include ?? $field->name;
        $fullToken = $prefix === '' ? $token : $prefix . '.' . $token;

        $tokens[$fullToken] = true;

        if (!$node->hasChildren()) {
            return;
        }

        // Sub-selection on a relation → walk into the target metadata,
        // bounded by MAX_DEPTH.
        if ($depth + 1 > self::MAX_DEPTH) {
            throw new GraphqlSelectionDepthExceededException(
                maxDepth: self::MAX_DEPTH,
                offendingPath: $fullToken . '.' . ($node->children()[0]->name ?? '?'),
            );
        }

        $target = $this->resolveTarget($field);
        if ($target === null) {
            // No metadata target available — selecting sub-fields here
            // would be guessing; fail loud so the client knows their
            // sub-selection cannot be honoured.
            throw new UnknownGraphqlFieldException(
                fieldPath: $fullToken,
                resourceType: $contextMetadata->type,
                reason: 'cannot resolve target type for sub-selection',
            );
        }

        foreach ($node->children() as $grandchild) {
            $this->walk($grandchild, $target, $fullToken, $depth + 1, $tokens);
        }
    }

    private function resolveTarget(ResourceFieldMetadata $field): ?ResourceObjectMetadata
    {
        if ($field->target !== null) {
            return $this->registry->get($field->target);
        }
        if ($field->unionTargets !== null && $field->unionTargets !== []) {
            return $this->registry->get($field->unionTargets[0]);
        }
        return null;
    }
}
