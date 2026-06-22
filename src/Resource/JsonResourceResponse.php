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
 * The runtime gate for `RenderProfile::Json`. Handlers return one of
 * these instead of building JSON by hand. Wraps the existing `ResourceResponse`
 * envelope so existing routing/middleware still works.
 *
 * Usage in a handler:
 *
 *     public function handle(GetCustomerPayload $payload, JsonResourceResponse $response): JsonResourceResponse
 *     {
 *         $customer = ...;                                       // build the DTO graph
 *         $context = new RenderContext(
 *             profile: RenderProfile::Json,
 *             includes: $payload->includes(),
 *             baseUrl:  $this->iri->baseUrl(),
 *         );
 *         return $response->withResource($customer, $context);
 *     }
 *
 * The class is container-managed (parameterless ctor, services on
 * `#[InjectAsReadonly]` properties) per Semitexa convention.
 */
class JsonResourceResponse extends ResourceResponse
{
    /** @var IncludeValidator|null Lazy-set in withResource() — kept on this slot for tests. */
    #[InjectAsReadonly]
    protected ?IncludeValidator $includeValidator = null;

    /** @var JsonResourceRenderer|null */
    #[InjectAsReadonly]
    protected ?JsonResourceRenderer $renderer = null;

    /** @var ResourceMetadataRegistry|null */
    #[InjectAsReadonly]
    protected ?ResourceMetadataRegistry $registry = null;

    /** Optional expansion pipeline for resolver-backed includes. */
    #[InjectAsReadonly]
    protected ?ResourceExpansionPipeline $expansionPipeline = null;

    /**
     * Wire the renderer + registry. The framework or the handler may call this;
     * either way the response is self-contained before content is set.
     */
    public function bindServices(
        JsonResourceRenderer $renderer,
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

    /**
     * Validate the request's includes (if any), render the Resource DTO graph,
     * and set the JSON content. Returns $this for fluency.
     */
    public function withResource(ResourceObjectInterface $resource, RenderContext $context): self
    {
        $this->ensureWired();

        \assert($this->registry !== null && $this->renderer !== null && $this->includeValidator !== null);

        $rootMetadata = $this->registry->require($resource::class);
        $this->includeValidator->validate($context->includes, $rootMetadata, $context->payloadClass);

        // Run the resolver pipeline for resolver-backed
        // (`#[ResolveWith]`) relations and attach the overlay to the
        // RenderContext. Eager handler-provided includes are
        // unaffected: the pipeline only touches relations whose
        // metadata declares a resolverClass.
        if ($this->expansionPipeline !== null) {
            $resolved = $this->expansionPipeline->expand($resource, $context->includes, $context);
            if (!$resolved->isEmpty()) {
                $context = $context->withResolved($resolved);
            }
        }

        $rendered = $this->renderer->render($resource, $context);

        $body = json_encode(
            ['data' => $rendered],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->setContent($body);
        $this->setStatusCode(HttpStatus::Ok->value);
        $this->setHeader('Content-Type', 'application/json');
        return $this;
    }

    /**
     * Render a list of Resource DTOs as a JSON
     * collection envelope `{"data": [ … ]}`, optionally with a
     * `meta.pagination` block when the caller supplies a resolved
     * {@see CollectionPage}. Includes are validated against the
     * caller-supplied resource class so empty collections still
     * exercise the chokepoint, and
     * `ResourceExpansionPipeline::expandMany()` batches
     * resolver-backed relations across all parents.
     *
     * The `$resources` list is expected to be **already paged** — the
     * handler slices the source collection before calling this
     * method so resolvers only fire for the visible parents.
     *
     * One Way Pattern: `$filterOptions` carries server-fed select
     * options for declared filterable fields —
     * `meta.filterOptions: { field: [{value, label}] }`. Supplied by
     * the handler; only routes that opt in pay the query cost. Null
     * (the default) keeps the envelope byte-identical.
     *
     * @param list<ResourceObjectInterface> $resources
     * @param class-string                  $resourceClass canonical Resource class for include
     *                                                      validation; required so empty
     *                                                      collections can still validate.
     * @param array<string, list<array{value: string, label: string}>>|null $filterOptions
     */
    public function withResources(
        array $resources,
        RenderContext $context,
        string $resourceClass,
        ?CollectionPage $page = null,
        ?CollectionCursorPage $cursorPage = null,
        ?array $filterOptions = null,
    ): self {
        $this->ensureWired();
        \assert($this->registry !== null && $this->renderer !== null && $this->includeValidator !== null);
        if ($page !== null && $cursorPage !== null) {
            // Page mode and cursor mode are mutually
            // exclusive. The handler must pick one; passing both is
            // a configuration bug we surface immediately.
            throw new \InvalidArgumentException(
                'JsonResourceResponse::withResources(): page and cursorPage are mutually exclusive.',
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

        $envelope = ['data' => $items];
        if ($page !== null) {
            $envelope['meta'] = ['pagination' => $page->toArray()];
        } elseif ($cursorPage !== null) {
            $envelope['meta'] = ['pagination' => $cursorPage->toArray()];
        }
        if ($filterOptions !== null && $filterOptions !== []) {
            $envelope['meta'] ??= [];
            $envelope['meta']['filterOptions'] = $filterOptions;
        }

        $body = json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->setContent($body);
        $this->setStatusCode(HttpStatus::Ok->value);
        $this->setHeader('Content-Type', 'application/json');
        return $this;
    }

    private function ensureWired(): void
    {
        if ($this->renderer === null || $this->registry === null || $this->includeValidator === null) {
            throw new \LogicException(
                'JsonResourceResponse is not wired. Either let the container inject it, '
                . 'or call ->bindServices() before ->withResource().',
            );
        }
    }
}
