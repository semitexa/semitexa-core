<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * One Way Pattern — Phase 1: the assembled route contract for one route.
 *
 * Joins the legacy OPTIONS document (`$base`, byte-for-byte what
 * {@see PayloadMetadataReflector::describe()} emits today — nothing in it
 * is removed or renamed) with the new ADDITIVE keys:
 *
 *   input       — always present ({@see ResolvedPayloadContract})
 *   <blocks>    — contributed named blocks, e.g. `collection`
 *   output      — present only when the response class resolves to
 *                 registered resource metadata ({@see ResolvedResourceContract})
 *
 * Degradation rule (design §1.1): a route with no resource metadata and no
 * contributed blocks serves exactly today's document plus `input` — no
 * empty `collection`/`output` stubs.
 *
 * The ETag is derived from the serialized document; the document is a pure
 * boot-time projection of attribute data, so the tag is stable across
 * requests and workers of the same build.
 */
final readonly class RouteContract
{
    /**
     * @param array<string, mixed> $base   the legacy OPTIONS document, unchanged
     * @param array<string, array<string, mixed>> $blocks contributed named blocks
     * @param bool $isCollection whether the route returns a collection of
     *        resources. A reliable cardinality signal even for bare collections
     *        that emit no `collection` block — see
     *        {@see \Semitexa\Core\Contract\CollectionAwareContributorInterface}.
     *
     *        DELIBERATELY NOT serialized into {@see self::toDocument()} (and so
     *        absent from the ETag). Trade-off, decided explicitly:
     *          - keeps the served OPTIONS document + ETag byte-stable, so the
     *            api OpenAPI/contract snapshots and any document-caching client
     *            are untouched by adding this flag;
     *          - a RICH collection is already wire-visible through its
     *            `collection` block (pagination/sort/filter), so the ONLY thing
     *            invisible on the wire is a *bare* collection's cardinality — a
     *            low-impact gap (a bare collection drives no grid/pagination).
     *        It is an in-process convenience for consumers like the GraphQL
     *        schema builder, which reads the RouteContract object directly. If a
     *        wire client ever needs bare-collection cardinality, surface it then
     *        (and accept the ETag/snapshot churn) rather than pre-emptively.
     */
    public function __construct(
        public array $base,
        public ResolvedPayloadContract $input,
        public ?ResolvedResourceContract $output,
        public array $blocks,
        public bool $isCollection = false,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function collectionBlock(): ?array
    {
        return $this->blocks['collection'] ?? null;
    }

    /** @return array<string, mixed> */
    public function toDocument(): array
    {
        $document = $this->base;
        $document['input'] = $this->input->toArray();
        foreach ($this->blocks as $key => $block) {
            $document[$key] = $block;
        }
        if ($this->output !== null) {
            $document['output'] = $this->output->toArray();
        }

        return $document;
    }

    public function etag(): string
    {
        return '"' . hash('sha256', json_encode($this->toDocument(), JSON_THROW_ON_ERROR)) . '"';
    }
}
