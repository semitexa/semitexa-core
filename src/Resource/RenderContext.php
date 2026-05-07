<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

final class RenderContext
{
    /** @var array<string, true> */
    private array $visited = [];
    private int $depth = 0;

    public function __construct(
        public readonly RenderProfile $profile,
        public readonly IncludeSet $includes,
        public readonly string $baseUrl = '',
        public readonly int $maxDepth = 8,
        /**
         * Phase 6c: FQCN of the payload class that produced this
         * render context. Used by `IncludeValidator` to look up
         * `#[HandlerProvidesResourceIncludes]` declarations and decide
         * whether requested include tokens are satisfiable.
         *
         * `null` means "no payload class declared" — handlers that do
         * not pass it will only be able to satisfy include tokens via
         * `#[ResolveWith]` resolvers.
         *
         * @var class-string|null
         */
        public readonly ?string $payloadClass = null,
        /**
         * Phase 6d: optional resolved-relation overlay produced by
         * `ResourceExpansionPipeline` for resolver-backed
         * (`#[ResolveWith]`) relations. Renderers consult the overlay
         * before reading the raw `ResourceRef` / `ResourceRefList` on
         * the DTO so resolver-backed includes appear embedded without
         * mutating the DTO. `null` = no overlay (eager-handler path
         * only).
         */
        public readonly ?ResolvedResourceGraph $resolved = null,
    ) {
    }

    /**
     * Phase 6d: build a child RenderContext that swaps the resolved
     * overlay. Used by Response classes that build the bare context
     * for include validation, then attach the overlay produced by the
     * expansion pipeline before handing the context to the renderer.
     * Returns a new immutable instance; the depth/visited mutable
     * state is intentionally fresh.
     */
    public function withResolved(ResolvedResourceGraph $resolved): self
    {
        return new self(
            profile:      $this->profile,
            includes:     $this->includes,
            baseUrl:      $this->baseUrl,
            maxDepth:     $this->maxDepth,
            payloadClass: $this->payloadClass,
            resolved:     $resolved,
        );
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function isVisited(ResourceIdentity $identity): bool
    {
        return isset($this->visited[$identity->urn()]);
    }

    public function enter(ResourceIdentity $identity): void
    {
        $this->visited[$identity->urn()] = true;
        $this->depth++;
    }

    public function leave(ResourceIdentity $identity): void
    {
        unset($this->visited[$identity->urn()]);
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    public function isAtMaxDepth(): bool
    {
        return $this->depth >= $this->maxDepth;
    }
}
