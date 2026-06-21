<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Pagination\CollectionPage;

/**
 * Runtime gate for `RenderProfile::GraphQL`. Parallel to
 * `JsonResourceResponse` and `JsonLdResourceResponse`.
 *
 * Sets `Content-Type: application/graphql-response+json` per the GraphQL
 * over HTTP draft. Body shape is the bounded GraphQL JSON envelope
 * documented on `GraphqlResourceRenderer`.
 *
 * Container-managed (parameterless ctor + `#[InjectAsReadonly]` properties).
 */
class GraphqlResourceResponse extends ResourceResponse
{
    #[InjectAsReadonly]
    protected ?IncludeValidator $includeValidator = null;

    #[InjectAsReadonly]
    protected ?GraphqlResourceRenderer $renderer = null;

    #[InjectAsReadonly]
    protected ?ResourceMetadataRegistry $registry = null;

    /** Optional expansion pipeline for resolver-backed includes. */
    #[InjectAsReadonly]
    protected ?ResourceExpansionPipeline $expansionPipeline = null;

    public function bindServices(
        GraphqlResourceRenderer $renderer,
        ResourceMetadataRegistry $registry,
        IncludeValidator $includeValidator,
        ?ResourceExpansionPipeline $expansionPipeline = null,
    ): self {
        $this->renderer          = $renderer;
        $this->registry          = $registry;
        $this->includeValidator  = $includeValidator;
        $this->expansionPipeline = $expansionPipeline;
        return $this;
    }

    public function withResource(ResourceObjectInterface $resource, RenderContext $context): self
    {
        $this->ensureWired();

        \assert($this->registry !== null && $this->renderer !== null && $this->includeValidator !== null);

        $rootMetadata = $this->registry->require($resource::class);
        $this->includeValidator->validate($context->includes, $rootMetadata, $context->payloadClass);

        // Resolver-backed expansion overlay.
        if ($this->expansionPipeline !== null) {
            $resolved = $this->expansionPipeline->expand($resource, $context->includes, $context);
            if (!$resolved->isEmpty()) {
                $context = $context->withResolved($resolved);
            }
        }

        $rendered = $this->renderer->render($resource, $context);

        $body = json_encode(
            $rendered,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->setContent($body);
        $this->setStatusCode(HttpStatus::Ok->value);
        $this->setHeader('Content-Type', 'application/graphql-response+json');
        return $this;
    }

    /**
     * Render a list of Resource DTOs as a GraphQL collection
     * envelope `{"data": {"<rootField>": [ … ]}}`. The caller picks a
     * deterministic `$rootFieldName` (e.g. `"customers"`) — the
     * renderer does not derive it from metadata for collection
     * responses since the canonical `rootFieldName(metadata)` returns
     * the singular form.
     *
     * @param list<ResourceObjectInterface> $resources
     * @param class-string                  $resourceClass canonical Resource class for
     *                                                      include validation.
     */
    public function withResources(
        string $rootFieldName,
        array $resources,
        RenderContext $context,
        string $resourceClass,
        ?CollectionPage $page = null,
        ?CollectionCursorPage $cursorPage = null,
    ): self {
        $this->ensureWired();
        \assert($this->registry !== null && $this->renderer !== null && $this->includeValidator !== null);

        if ($rootFieldName === '') {
            throw new \InvalidArgumentException(
                'GraphqlResourceResponse::withResources() requires a non-empty root field name.',
            );
        }
        if ($page !== null && $cursorPage !== null) {
            throw new \InvalidArgumentException(
                'GraphqlResourceResponse::withResources(): page and cursorPage are mutually exclusive.',
            );
        }

        $rootMetadata = $this->registry->require($resourceClass);
        $this->includeValidator->validate($context->includes, $rootMetadata, $context->payloadClass);

        if ($this->expansionPipeline !== null && $resources !== []) {
            $resolved = $this->expansionPipeline->expandMany($resources, $context->includes, $context);
            if (!$resolved->isEmpty()) {
                $context = $context->withResolved($resolved);
            }
        }

        $items = [];
        foreach ($resources as $resource) {
            $items[] = $this->renderer->renderNode($resource, $context);
        }

        $envelope = ['data' => [$rootFieldName => $items]];
        if ($page !== null) {
            // GraphQL keeps `meta` at the top level (sibling of `data`),
            // mirroring the JSON / JSON-LD shape. Embedding
            // pagination inside `data` would conflict with bare GraphQL
            // selection-set expectations. Documented as the smallest
            // consistent shape across the three profiles.
            $envelope['meta'] = ['pagination' => $page->toArray()];
        } elseif ($cursorPage !== null) {
            $envelope['meta'] = ['pagination' => $cursorPage->toArray()];
        }

        $body = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->setContent($body);
        $this->setStatusCode(HttpStatus::Ok->value);
        $this->setHeader('Content-Type', 'application/graphql-response+json');
        return $this;
    }

    private function ensureWired(): void
    {
        if ($this->renderer === null || $this->registry === null || $this->includeValidator === null) {
            throw new \LogicException(
                'GraphqlResourceResponse is not wired. Either let the container inject it, '
                . 'or call ->bindServices() before ->withResource().',
            );
        }
    }
}
