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
 * Runtime gate for `RenderProfile::JsonLd`. Parallel to
 * `JsonResourceResponse` — handlers return one of these
 * instead of building JSON-LD by hand.
 *
 * Usage in a handler:
 *
 *     public function handle(GetCustomerLdPayload $payload, JsonLdResourceResponse $response): JsonLdResourceResponse
 *     {
 *         $customer = ...;
 *         $context = new RenderContext(
 *             profile:  RenderProfile::JsonLd,
 *             includes: $payload->includes(),
 *             baseUrl:  $this->iri->baseUrl(),
 *         );
 *         return $response->withResource($customer, $context);
 *     }
 *
 * Container-managed (parameterless ctor + #[InjectAsReadonly] properties)
 * — matches the JsonResourceResponse pattern.
 */
class JsonLdResourceResponse extends ResourceResponse
{
    #[InjectAsReadonly]
    protected ?IncludeValidator $includeValidator = null;

    /** Optional expansion pipeline for resolver-backed includes. */
    #[InjectAsReadonly]
    protected ?ResourceExpansionPipeline $expansionPipeline = null;

    #[InjectAsReadonly]
    protected ?JsonLdResourceRenderer $renderer = null;

    #[InjectAsReadonly]
    protected ?ResourceMetadataRegistry $registry = null;

    public function bindServices(
        JsonLdResourceRenderer $renderer,
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
        $this->setHeader('Content-Type', 'application/ld+json');
        return $this;
    }

    /**
     * Render a list of Resource DTOs as a JSON-LD
     * collection document
     * `{"@context": …, "@graph": [ … ], "meta": {"pagination": …}}`.
     * `@context` lives exactly once at the document root; each
     * `@graph` entry is the bare node form. `meta.pagination` is
     * emitted when the caller supplies a {@see CollectionPage}.
     * `meta` lives at the document root (alongside `@context` and
     * `@graph`); Hydra-style `hydra:Collection` integration is
     * deferred to a later phase.
     *
     * @param list<ResourceObjectInterface> $resources
     * @param class-string                  $resourceClass canonical Resource class for
     *                                                      include validation.
     */
    public function withResources(
        array $resources,
        RenderContext $context,
        string $resourceClass,
        ?CollectionPage $page = null,
        ?CollectionCursorPage $cursorPage = null,
    ): self {
        $this->ensureWired();
        \assert($this->registry !== null && $this->renderer !== null && $this->includeValidator !== null);
        if ($page !== null && $cursorPage !== null) {
            throw new \InvalidArgumentException(
                'JsonLdResourceResponse::withResources(): page and cursorPage are mutually exclusive.',
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

        $nodes = [];
        foreach ($resources as $resource) {
            $nodes[] = $this->renderer->renderNode($resource, $context);
        }

        $envelope = [
            '@context' => JsonLdResourceRenderer::DEFAULT_VOCAB,
            '@graph'   => $nodes,
        ];
        if ($page !== null) {
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
        $this->setHeader('Content-Type', 'application/ld+json');
        return $this;
    }

    private function ensureWired(): void
    {
        if ($this->renderer === null || $this->registry === null || $this->includeValidator === null) {
            throw new \LogicException(
                'JsonLdResourceResponse is not wired. Either let the container inject it, '
                . 'or call ->bindServices() before ->withResource().',
            );
        }
    }
}
