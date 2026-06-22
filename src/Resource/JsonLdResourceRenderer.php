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
 * JSON-LD renderer. Produces the canonical JSON-LD shape
 * for a Resource DTO graph.
 *
 * Output rules (closed for v1):
 *
 *   - Every Resource DTO node carries `@id` and `@type`.
 *   - `@type` always comes from `ResourceObjectMetadata::$type`.
 *   - `@id` uses `href` when present (e.g., a handler-resolved IRI),
 *     otherwise falls back to `ResourceIdentity::urn()`. The renderer
 *     never invents URLs.
 *   - Document root carries `@context` mapping JSON-LD keywords to a
 *     framework-local vocabulary URI. `@context` is **not** emitted on
 *     nested objects.
 *   - `ResourceRef` reference-only → `{"@id", "@type"}`.
 *   - `ResourceRef` embedded → full nested object with `@id`, `@type`,
 *     scalar fields, nested relations.
 *   - `ResourceRefList` reference-only with href → `{"@id": <href>}` (Hydra
 *     collection link). With no href → `null` (defensive, matches the
 *     to(string $href) factory contract).
 *   - `ResourceRefList` embedded → JSON array of nested JSON-LD nodes.
 *   - Empty embedded collection → `[]`.
 *   - Optional `?ResourceRef` with null value → `null`.
 *   - Cycle / max-depth fallback → identity-only `{"@id", "@type"}`.
 *
 * Pure: zero IO, zero ORM, zero HTTP, zero Request access. Reuses the
 * metadata graph; never invokes `JsonResourceRenderer` or
 * `IriBuilder`.
 */
#[AsService]
final class JsonLdResourceRenderer
{
    /**
     * Default JSON-LD vocabulary URI. Framework-local — projects can layer a
     * project-specific vocabulary later via the `RenderContext::$baseUrl`
     * pattern, but v1 keeps it deterministic and offline-friendly.
     */
    public const DEFAULT_VOCAB = 'urn:semitexa:vocab/v1';

    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    /** Bypass property injection for unit tests. */
    public static function forTesting(ResourceMetadataRegistry $registry): self
    {
        $r = new self();
        $r->registry = $registry;
        return $r;
    }

    /**
     * @return array<string, mixed> JSON-LD document ready for json_encode().
     */
    public function render(ResourceObjectInterface $resource, RenderContext $context): array
    {
        if ($context->profile !== RenderProfile::JsonLd) {
            throw new UnsupportedRenderProfileException($context->profile, self::class);
        }

        $document = $this->renderObject($resource, $context, $context->includes);
        if (!is_array($document)) {
            // Cycle/depth at root — emit identity-only with @context still attached.
            $document = ['@id' => $document['@id'] ?? '', '@type' => $document['@type'] ?? ''];
        }

        return ['@context' => self::DEFAULT_VOCAB] + $document;
    }

    /**
     * Render a single Resource DTO as a bare JSON-LD node,
     * without the document-level `@context` wrapper. Collection
     * responses stamp `@context` once at the document root and call
     * `renderNode()` per item so `@context` does not leak onto every
     * `@graph` entry.
     *
     * @return array<string, mixed>
     */
    public function renderNode(ResourceObjectInterface $resource, RenderContext $context): array
    {
        if ($context->profile !== RenderProfile::JsonLd) {
            throw new UnsupportedRenderProfileException($context->profile, self::class);
        }
        return $this->renderObject($resource, $context, $context->includes);
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

        // Cycle / max-depth fallback: identity-only node reference.
        if ($identity !== null) {
            if ($context->isVisited($identity) || $context->isAtMaxDepth()) {
                return $this->identityNode($identity, null);
            }
            $context->enter($identity);
        }

        try {
            $node = $identity !== null
                ? $this->identityNode($identity, null)
                : ['@type' => $metadata->type];

            foreach ($metadata->fields as $field) {
                if ($field->name === $metadata->idField) {
                    // The id field is folded into @id; do not duplicate it as a property.
                    continue;
                }
                $rendered = $this->renderField($resource, $metadata, $field, $context, $includes, $identity);
                $node[$field->name] = $rendered;
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
            ResourceFieldKind::EmbeddedOne  => $this->renderEmbeddedOne($value, $context, $includes->nested($field->include ?? $field->name)),
            ResourceFieldKind::EmbeddedMany => $this->renderEmbeddedMany($value, $context, $includes->nested($field->include ?? $field->name)),
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
        $result = [];
        foreach ($value as $item) {
            if ($item instanceof ResourceObjectInterface) {
                $result[] = $this->renderObject($item, $context, $nested);
            }
        }
        return $result;
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
            // Nested resolver-backed relation whose bare DTO
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

        // Resolver-backed overlay. Prefer the pipeline's
        // resolved DTO when present.
        if ($parentIdentity !== null
            && $context->resolved !== null
            && $context->resolved->has($parentIdentity, $field->name)
        ) {
            $resolvedDto = $context->resolved->lookup($parentIdentity, $field->name);
            if ($resolvedDto instanceof ResourceObjectInterface) {
                $nested = $this->renderObject($resolvedDto, $context, $includes->nested($field->include));
                if ($value->href !== null && $value->href !== '') {
                    $nested['@id'] = $value->href;
                }
                return $nested;
            }
            // Resolver said "absent" — render link-only via identity.
            return $this->identityNode($value->identity, $value->href);
        }

        $shouldEmbed = $this->shouldEmbed($field, $includes);
        if ($shouldEmbed && $value->data === null) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        if ($value->data !== null) {
            // Embedded: render as a full nested JSON-LD node. Identity is
            // taken from the embedded data via its metadata.
            $nested = $this->renderObject($value->data, $context, $includes->nested($field->include));
            // If the parent ref carried an explicit href and the nested @id
            // came from urn fallback, prefer the href as the canonical @id.
            if ($value->href !== null && $value->href !== '') {
                $nested['@id'] = $value->href;
            }
            return $nested;
        }

        // Reference-only: emit a JSON-LD node reference using identity + optional href.
        return $this->identityNode($value->identity, $value->href);
    }

    /**
     * Reference-only collection: `{"@id": <href>}` when href present;
     * `null` otherwise (a non-empty href is mandatory through
     * the public factory, so the null branch is only reachable via direct
     * constructor — defensive symmetry with `JsonResourceRenderer`).
     *
     * Embedded: array of JSON-LD nodes.
     *
     * @return list<array<string, mixed>>|array{@id: string}|null
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

        // Resolver-backed to-many overlay.
        if ($parentIdentity !== null
            && $context->resolved !== null
            && $context->resolved->has($parentIdentity, $field->name)
        ) {
            $resolvedList = $context->resolved->lookup($parentIdentity, $field->name);
            if (is_array($resolvedList)) {
                $nested = $includes->nested($field->include);
                $items  = [];
                foreach ($resolvedList as $item) {
                    if ($item instanceof ResourceObjectInterface) {
                        $items[] = $this->renderObject($item, $context, $nested);
                    }
                }
                return $items;
            }
        }

        $shouldEmbed = $this->shouldEmbed($field, $includes);
        if ($shouldEmbed && $value->data === null) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        if ($value->data !== null) {
            $nested = $includes->nested($field->include);
            $items  = [];
            foreach ($value->data as $item) {
                if ($item instanceof ResourceObjectInterface) {
                    $items[] = $this->renderObject($item, $context, $nested);
                }
            }
            return $items;
        }

        // Reference-only.
        if ($value->href !== null && $value->href !== '') {
            return ['@id' => $value->href];
        }

        // Direct-constructor (data=null, href=null): emit JSON null rather
        // than an indistinguishable empty `{}` — matches the
        // contract from the JSON renderer.
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
     * @return array{"@id": string, "@type": string}
     */
    private function identityNode(ResourceIdentity $identity, ?string $href): array
    {
        return [
            '@id'   => ($href !== null && $href !== '') ? $href : $identity->urn(),
            '@type' => $identity->type,
        ];
    }

    /**
     * Render a JSON-LD nested node from the overlay alone,
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
            // Overlay said "no related entity" → no node; matches
            // bare-`null` semantics for an optional relation.
            return null;
        }
        return $this->renderObject($resolvedDto, $context, $includes->nested($field->include));
    }
}
