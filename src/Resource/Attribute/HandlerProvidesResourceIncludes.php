<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

/**
 * Phase 6c: explicit declaration that a payload's handler eagerly embeds
 * specific include tokens for a given root Resource DTO.
 *
 * The Phase 6c `IncludeValidator` accepts an expandable include token
 * only when the framework knows how the relation will arrive. Two
 * mechanisms qualify:
 *
 *   1. The relation field has `#[ResolveWith(...)]` — the future Phase
 *      6d expansion pipeline will load it.
 *   2. The payload's class declares `#[HandlerProvidesResourceIncludes]`
 *      and the requested token is in that list — the handler eagerly
 *      embeds it itself.
 *
 * This attribute makes "the handler will provide it" an *explicit*,
 * route-level contract instead of an implicit fallback. `lint:resources`
 * statically validates each declared token against the named root
 * resource's metadata graph (must exist, be a relation, be expandable);
 * stale or scalar tokens fail loudly at lint time.
 *
 * Example:
 *
 *     #[AsPayload(...)]
 *     #[HandlerProvidesResourceIncludes(
 *         resource: CustomerResource::class,
 *         tokens: ['addresses', 'profile'],
 *     )]
 *     final class GetCustomerPayload implements SupportsResourceIncludes {}
 *
 * Pure metadata. Phase 6c never instantiates the handler from the
 * attribute; it only consults the registry to satisfy include validation.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class HandlerProvidesResourceIncludes
{
    /**
     * @param class-string $resource the root Resource DTO class the
     *                                 handler embeds these includes on
     * @param list<string> $tokens   include tokens (top-level or
     *                                 dot-notation) the handler eagerly
     *                                 embeds; normalized at registry
     *                                 build time (lowercase, deduped,
     *                                 sorted)
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $tokens,
    ) {
    }
}
