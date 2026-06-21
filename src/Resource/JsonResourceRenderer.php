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
 * Produces the canonical JSON envelope for a Resource DTO graph.
 *
 * Output shape (decision D5):
 *   to-one ref:   { "type": "...", "id": "...", "href"?: "...", "data"?: {...} }
 *   to-many ref:  { "href"?: "...", "data"?: [...], "total"?: N }
 *
 * The renderer is pure: zero IO, no DB, no ORM, no HTTP, no service container
 * lookups during the render. Hand it a fully-built ResourceDTO graph; it
 * returns a deterministic PHP array ready for `json_encode()`.
 */
#[AsService]
final class JsonResourceRenderer
{
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
     * @return array<string, mixed>
     */
    public function render(ResourceObjectInterface $resource, RenderContext $context): array
    {
        if ($context->profile !== RenderProfile::Json) {
            throw new UnsupportedRenderProfileException($context->profile, self::class);
        }

        return $this->renderObject($resource, $context, $context->includes);
    }

    /**
     * Bare-node alias of {@see render()}. The JSON profile
     * has no document-level wrapper (the `data` envelope is added by
     * `JsonResourceResponse`), so `renderNode()` simply forwards.
     * Provided for symmetry with the JSON-LD and GraphQL renderers
     * so collection responses can use one method name across all
     * three profiles.
     *
     * @return array<string, mixed>
     */
    public function renderNode(ResourceObjectInterface $resource, RenderContext $context): array
    {
        return $this->render($resource, $context);
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
            if ($context->isVisited($identity)) {
                // Cycle: degrade to identity-only.
                return $this->refOnlyEnvelope($identity, null);
            }
            if ($context->isAtMaxDepth()) {
                return $this->refOnlyEnvelope($identity, null);
            }
            $context->enter($identity);
        }

        try {
            $output = [];
            foreach ($metadata->fields as $field) {
                $output[$field->name] = $this->renderField(
                    $resource,
                    $metadata,
                    $field,
                    $context,
                    $includes,
                    $identity,
                );
            }

            return $output;
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
        if ($value === null) {
            return null;
        }
        if (!$value instanceof ResourceObjectInterface) {
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
            // An optional resolver-backed nested relation may
            // have its `?ResourceRef` slot declared `null` on a freshly
            // constructed parent DTO; the actual data lives on the
            // overlay. Render from overlay alone when the pipeline put
            // a slot there.
            $envelope = $this->overlayOnlyRefOne($field, $context, $includes, $parentIdentity);
            if ($envelope !== null) {
                return $envelope;
            }
            // Optional relation declared `?ResourceRef` and not present.
            return null;
        }
        if (!$value instanceof ResourceRef) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        $envelope = [
            'type' => $value->identity->type,
            'id'   => $value->identity->id,
        ];
        if ($value->href !== null) {
            $envelope['href'] = $value->href;
        }

        $shouldEmbed = $this->shouldEmbed($field, $includes);

        // Resolver-backed overlay. If the pipeline expanded
        // this relation, prefer the resolved DTO over whatever is on
        // the bare ResourceRef. A `has()` hit with a `null` value means
        // the resolver explicitly said "no related entity"; render
        // link-only.
        if ($parentIdentity !== null
            && $context->resolved !== null
            && $context->resolved->has($parentIdentity, $field->name)
        ) {
            $resolvedDto = $context->resolved->lookup($parentIdentity, $field->name);
            if ($resolvedDto instanceof ResourceObjectInterface) {
                $envelope['data'] = $this->renderObject($resolvedDto, $context, $includes->nested($field->include));
            }
            return $envelope;
        }

        if ($shouldEmbed && $value->data === null) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        if ($value->data !== null) {
            $envelope['data'] = $this->renderObject($value->data, $context, $includes->nested($field->include));
        }

        return $envelope;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function renderRefMany(
        ResourceObjectMetadata $parentMetadata,
        ResourceFieldMetadata $field,
        mixed $value,
        RenderContext $context,
        IncludeSet $includes,
        ?ResourceIdentity $parentIdentity = null,
    ): ?array {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof ResourceRefList) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        // Resolver-backed to-many overlay. The pipeline
        // returned a list of resource DTOs for this parent; render
        // them as if they were embedded by the handler.
        if ($parentIdentity !== null
            && $context->resolved !== null
            && $context->resolved->has($parentIdentity, $field->name)
        ) {
            $resolvedList = $context->resolved->lookup($parentIdentity, $field->name);
            if (is_array($resolvedList)) {
                $envelope = [];
                if ($value->href !== null && $value->href !== '') {
                    $envelope['href'] = $value->href;
                }
                $nested = $includes->nested($field->include);
                $items  = [];
                foreach ($resolvedList as $item) {
                    if ($item instanceof ResourceObjectInterface) {
                        $items[] = $this->renderObject($item, $context, $nested);
                    }
                }
                $envelope['data']  = $items;
                $envelope['total'] = count($items);
                return $envelope;
            }
        }

        $shouldEmbed = $this->shouldEmbed($field, $includes);
        if ($shouldEmbed && $value->data === null) {
            throw new UnloadedRelationException($parentMetadata->type, $field->name);
        }

        // Reference-only without href is unreachable through ResourceRefList::to(),
        // which enforces a non-empty href. If a developer bypasses the factory and
        // constructs (data=null, href=null), the renderer emits a single `null`
        // rather than an indistinguishable empty `{}`.
        if ($value->data === null && ($value->href === null || $value->href === '')) {
            return null;
        }

        $envelope = [];
        if ($value->href !== null && $value->href !== '') {
            $envelope['href'] = $value->href;
        }

        if ($value->data !== null) {
            $nested = $includes->nested($field->include);
            $items  = [];
            foreach ($value->data as $item) {
                if ($item instanceof ResourceObjectInterface) {
                    $items[] = $this->renderObject($item, $context, $nested);
                }
            }
            $envelope['data']  = $items;
            $envelope['total'] = $value->total ?? count($items);
        } elseif ($value->total !== null) {
            $envelope['total'] = $value->total;
        }

        return $envelope;
    }

    /**
     * Polymorphic relation. The renderer trusts the runtime envelope type; the
     * extractor and validator have already proved that the field is a valid
     * union with registered targets.
     *
     * @return array<string, mixed>|null
     */
    private function renderUnion(
        ResourceObjectMetadata $parentMetadata,
        ResourceFieldMetadata $field,
        mixed $value,
        RenderContext $context,
        IncludeSet $includes,
        ?ResourceIdentity $parentIdentity = null,
    ): ?array {
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

    /**
     * Build a JSON ref-one envelope from the overlay only,
     * for the case where the parent DTO carries `null` for this
     * relation slot but the resolver pipeline produced a value. Type
     * and id come from the resolved DTO's own metadata; href is
     * unavailable in this branch (the field's `hrefTemplate` formatter
     * is owned by callers that know the parent context, not by the
     * renderer).
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
            // Overlay said "no related entity" → no envelope; matches
            // bare-`null` semantics for an optional relation.
            return null;
        }
        $childMetadata = $this->registry->require($resolvedDto::class);
        $envelope = ['type' => $childMetadata->type];
        if ($childMetadata->idField !== null) {
            $envelope['id'] = $this->extractIdentity($resolvedDto, $childMetadata)->id;
        }
        $envelope['data'] = $this->renderObject(
            $resolvedDto,
            $context,
            $includes->nested($field->include),
        );
        return $envelope;
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
     * @return array<string, mixed>
     */
    private function refOnlyEnvelope(ResourceIdentity $identity, ?string $href): array
    {
        $envelope = [
            'type' => $identity->type,
            'id'   => $identity->id,
        ];
        if ($href !== null) {
            $envelope['href'] = $href;
        }
        return $envelope;
    }
}
