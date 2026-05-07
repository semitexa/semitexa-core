<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Exception\UnloadedRelationException;
use Semitexa\Core\Resource\Exception\UnsupportedRenderProfileException;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * Phase 5a: bounded GraphQL render profile.
 *
 * **Deliberately NOT a GraphQL query executor.** This renderer serializes
 * an already-prepared Resource DTO graph into a GraphQL-compatible JSON
 * envelope. Field selection comes from the existing `?include=` mechanism
 * (handler decides what to embed); the renderer never parses query
 * documents, never resolves arbitrary client selections, never lazy-loads.
 *
 * Output envelope:
 *
 *   {
 *     "data": {
 *       "<rootFieldName>": {
 *         "id": "...",
 *         "type": "...",
 *         "<scalar fields>": ...,
 *         "<relation fields>": ...
 *       }
 *     }
 *   }
 *
 * Root field name is derived deterministically from the resource type
 * (e.g. `customer` from `ResourceObject(type: 'customer')`). Type names
 * containing dots collapse to the last segment so `catalog.customer`
 * becomes `customer` in the wire response.
 *
 * Relation rendering rules (parallel to JSON renderer, but flattened —
 * no `data`/`href` envelope wrapper for embedded data):
 *
 *   - to-one ref-only       → `{id, type, href?}`
 *   - to-one embedded       → `{id, type, ...fields}` (the inner object)
 *   - to-one nullable null  → `null`
 *   - to-many ref-only      → `{href}` (collection link)
 *   - to-many embedded      → `[{...}, {...}]`
 *   - to-many empty         → `[]`
 *   - to-many null/null     → `null` (defensive symmetry with JSON profile)
 *   - cycle / max depth     → identity-only `{id, type}`
 *
 * Pure: zero IO, zero Request access, zero IriBuilder, zero
 * JsonResourceRenderer, zero JsonLdResourceRenderer, zero ORM, zero HTTP.
 */
#[AsService]
final class GraphqlResourceRenderer
{
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    public static function forTesting(ResourceMetadataRegistry $registry): self
    {
        $r = new self();
        $r->registry = $registry;
        return $r;
    }

    /**
     * Render the root resource into a GraphQL-shaped envelope.
     *
     * @return array<string, mixed>
     */
    public function render(ResourceObjectInterface $resource, RenderContext $context): array
    {
        if ($context->profile !== RenderProfile::GraphQL) {
            throw new UnsupportedRenderProfileException($context->profile, self::class);
        }

        $metadata = $this->registry->require($resource::class);
        $rootField = $this->rootFieldName($metadata);

        return [
            'data' => [
                $rootField => $this->renderObject($resource, $context, $context->includes),
            ],
        ];
    }

    /**
     * Phase 6h: render a single Resource DTO as a bare GraphQL node,
     * without the `{data: {<rootField>: …}}` wrapper. Collection
     * responses stamp the `data` envelope once at the document root,
     * pick a deterministic root field name (e.g. pluralized resource
     * type), and call `renderNode()` per item.
     *
     * @return array<string, mixed>
     */
    public function renderNode(ResourceObjectInterface $resource, RenderContext $context): array
    {
        if ($context->profile !== RenderProfile::GraphQL) {
            throw new UnsupportedRenderProfileException($context->profile, self::class);
        }
        return $this->renderObject($resource, $context, $context->includes);
    }

    /**
     * Public deterministic helper: derive the GraphQL root field name from
     * a resource type. Exposed so other layers (tests, future schema
     * generators) can compute the same value without duplicating the rule.
     */
    public function rootFieldName(ResourceObjectMetadata $metadata): string
    {
        $type = $metadata->type;
        // Collapse `<namespace>.customer` → `customer`. Application code
        // may namespace resource types; the wire-side root field name
        // is the final segment.
        $dot = strrpos($type, '.');
        return $dot === false ? $type : substr($type, $dot + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderObject(
        ResourceObjectInterface $resource,
        RenderContext $context,
        IncludeSet $includes,
    ): array {
        $metadata = $this->registry->require($resource::class);
        $identity = $metadata->idField !== null ? $this->extractIdentity($resource, $metadata) : null;

        if ($identity !== null) {
            if ($context->isVisited($identity) || $context->isAtMaxDepth()) {
                return $this->identityOnly($identity);
            }
            $context->enter($identity);
        }

        try {
            $node = $identity !== null
                ? $this->identityOnly($identity)
                : ['type' => $metadata->type];

            foreach ($metadata->fields as $field) {
                if ($field->name === $metadata->idField) {
                    // Already rendered as `id`; do not duplicate as a property.
                    continue;
                }
                $node[$field->name] = $this->renderField(
                    $resource,
                    $metadata,
                    $field,
                    $context,
                    $includes,
                    $identity,
                );
            }

            return $node;
        } finally {
            if ($identity !== null) {
                $context->leave($identity);
            }
        }
    }

    private function renderField(
        ResourceObjectInterface $resource,
        ResourceObjectMetadata $metadata,
        ResourceFieldMetadata $field,
        RenderContext $context,
        IncludeSet $includes,
        ?ResourceIdentity $parentIdentity,
    ): mixed {
        $value = $this->readPublicProperty($resource, $field->name);

        return match ($field->kind) {
            ResourceFieldKind::Scalar       => $value,
            ResourceFieldKind::EmbeddedOne  => $this->renderEmbeddedOne($value, $context, $includes->nested($field->name)),
            ResourceFieldKind::EmbeddedMany => $this->renderEmbeddedMany($value, $context, $includes->nested($field->name)),
            ResourceFieldKind::RefOne       => $this->renderRefOne($metadata, $field, $value, $context, $includes, $parentIdentity),
            ResourceFieldKind::RefMany      => $this->renderRefMany($metadata, $field, $value, $context, $includes, $parentIdentity),
            ResourceFieldKind::Union        => $this->renderUnion($metadata, $field, $value, $context, $includes, $parentIdentity),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function renderEmbeddedOne(mixed $value, RenderContext $context, IncludeSet $nested): ?array
    {
        if ($value === null || !$value instanceof ResourceObjectInterface) {
            return null;
        }
        return $this->renderObject($value, $context, $nested);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renderEmbeddedMany(mixed $value, RenderContext $context, IncludeSet $nested): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if ($item instanceof ResourceObjectInterface) {
                $out[] = $this->renderObject($item, $context, $nested);
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function renderRefOne(
        ResourceObjectMetadata $parentMetadata,
        ResourceFieldMetadata $field,
        mixed $value,
        RenderContext $context,
        IncludeSet $includes,
        ?ResourceIdentity $parentIdentity = null,
    ): ?array {
        if ($value === null) {
            // Phase 6g: nested resolver-backed relation whose bare DTO
            // slot is `null` — render from overlay alone.
            $node = $this->overlayOnlyRefOne($field, $context, $includes, $parentIdentity);
            if ($node !== null) {
                return $node;
            }
            return null;
        }
        if (!$value instanceof ResourceRef) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        // Phase 6d: resolver-backed overlay.
        if ($parentIdentity !== null
            && $context->resolved !== null
            && $context->resolved->has($parentIdentity, $field->name)
        ) {
            $resolvedDto = $context->resolved->lookup($parentIdentity, $field->name);
            if ($resolvedDto instanceof ResourceObjectInterface) {
                return $this->renderObject($resolvedDto, $context, $includes->nested($field->name));
            }
            // Resolver said "absent" — render link-only.
            $node = [
                'id'   => $value->identity->id,
                'type' => $value->identity->type,
            ];
            if ($value->href !== null && $value->href !== '') {
                $node['href'] = $value->href;
            }
            return $node;
        }

        $shouldEmbed = $this->shouldEmbed($field, $includes);
        if ($shouldEmbed && $value->data === null) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        if ($value->data !== null) {
            // Embedded: render as a flat object using the embedded data's
            // own metadata. The href on the parent ref is GraphQL-irrelevant
            // when data is present.
            return $this->renderObject($value->data, $context, $includes->nested($field->name));
        }

        // Reference-only: lightweight {id, type, href?} object.
        $node = [
            'id'   => $value->identity->id,
            'type' => $value->identity->type,
        ];
        if ($value->href !== null && $value->href !== '') {
            $node['href'] = $value->href;
        }
        return $node;
    }

    /**
     * @return list<array<string, mixed>>|array{href: string}|null
     */
    private function renderRefMany(
        ResourceObjectMetadata $parentMetadata,
        ResourceFieldMetadata $field,
        mixed $value,
        RenderContext $context,
        IncludeSet $includes,
        ?ResourceIdentity $parentIdentity = null,
    ): array|null {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof ResourceRefList) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        // Phase 6d: resolver-backed to-many overlay.
        if ($parentIdentity !== null
            && $context->resolved !== null
            && $context->resolved->has($parentIdentity, $field->name)
        ) {
            $resolvedList = $context->resolved->lookup($parentIdentity, $field->name);
            if (is_array($resolvedList)) {
                $nested = $includes->nested($field->name);
                $out    = [];
                foreach ($resolvedList as $item) {
                    if ($item instanceof ResourceObjectInterface) {
                        $out[] = $this->renderObject($item, $context, $nested);
                    }
                }
                return $out;
            }
        }

        $shouldEmbed = $this->shouldEmbed($field, $includes);
        if ($shouldEmbed && $value->data === null) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        if ($value->data !== null) {
            $nested = $includes->nested($field->name);
            $out = [];
            foreach ($value->data as $item) {
                if ($item instanceof ResourceObjectInterface) {
                    $out[] = $this->renderObject($item, $context, $nested);
                }
            }
            return $out;
        }

        if ($value->href !== null && $value->href !== '') {
            return ['href' => $value->href];
        }

        // Defensive parity with JSON / JSON-LD profiles for the (data=null,
        // href=null) direct-constructor case — emit JSON null rather than
        // an indistinguishable empty object.
        return null;
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function renderUnion(
        ResourceObjectMetadata $parentMetadata,
        ResourceFieldMetadata $field,
        mixed $value,
        RenderContext $context,
        IncludeSet $includes,
        ?ResourceIdentity $parentIdentity = null,
    ): array|null {
        if ($field->isList()) {
            return $this->renderRefMany($parentMetadata, $field, $value, $context, $includes, $parentIdentity);
        }
        return $this->renderRefOne($parentMetadata, $field, $value, $context, $includes, $parentIdentity);
    }

    private function shouldEmbed(ResourceFieldMetadata $field, IncludeSet $includes): bool
    {
        if ($field->include === null) {
            return false;
        }
        return $includes->has($field->include);
    }

    private function readPublicProperty(ResourceObjectInterface $resource, string $name): mixed
    {
        $vars = get_object_vars($resource);
        return $vars[$name] ?? null;
    }

    private function extractIdentity(
        ResourceObjectInterface $resource,
        ResourceObjectMetadata $metadata,
    ): ResourceIdentity {
        $idField = $metadata->idField;
        if ($idField === null) {
            throw new \LogicException('extractIdentity called on resource without idField.');
        }
        $vars = get_object_vars($resource);
        $id   = $vars[$idField] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \LogicException(sprintf(
                'Resource %s::$%s did not yield a non-empty string id.',
                $metadata->class,
                $idField,
            ));
        }
        return new ResourceIdentity($metadata->type, $id);
    }

    /**
     * @return array{id: string, type: string}
     */
    private function identityOnly(ResourceIdentity $identity): array
    {
        return [
            'id'   => $identity->id,
            'type' => $identity->type,
        ];
    }

    /**
     * Phase 6g: render a GraphQL nested object from the overlay alone,
     * for the case where the parent DTO carries `null` for this
     * relation slot but the resolver pipeline produced a value.
     *
     * @return array<string, mixed>|null
     */
    private function overlayOnlyRefOne(
        ResourceFieldMetadata $field,
        RenderContext $context,
        IncludeSet $includes,
        ?ResourceIdentity $parentIdentity,
    ): ?array {
        if ($parentIdentity === null
            || $context->resolved === null
            || !$context->resolved->has($parentIdentity, $field->name)
        ) {
            return null;
        }
        $resolvedDto = $context->resolved->lookup($parentIdentity, $field->name);
        if (!$resolvedDto instanceof ResourceObjectInterface) {
            // Overlay said "no related entity" → no node, matching
            // bare-`null` semantics for an optional relation.
            return null;
        }
        return $this->renderObject($resolvedDto, $context, $includes->nested($field->name));
    }
}
