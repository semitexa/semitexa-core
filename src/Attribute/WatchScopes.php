<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * One Way Pattern · Phase 4 — declares the invalidation scope keys a
 * collection feed's held-open SSE stream subscribes to.
 *
 * Placed on a feed PAYLOAD class (the route declaration carrier), it is the
 * API-surface successor of the platform-ui `#[GridFeed(liveOn:)]` component
 * declaration: the SSE collection serving base
 * (`Semitexa\Ssr\...\AbstractSseCollectionFeedHandler`) resolves the
 * held-open subscription's `SubscriptionRecord::$scopeKeys` from it, and the
 * route contract serves it as `collection.live.scopes` so a metadata-driven
 * client can see WHY the grid is live. Name-aligned with
 * `ExposeAsGraphql(watchScopes:)`, which expresses the identical concept for
 * GraphQL subscriptions on the same Track-R substrate — one declaration
 * vocabulary, both transports.
 *
 * Scope keys are the publisher-side resource keys carried by
 * `ui.invalidate.{tenant}.{scope}` (for ORM-backed feeds the table name by
 * default, e.g. `ui_playground_pings`). Multiple scopes subscribe with OR
 * semantics: ANY firing re-runs the feed, a burst coalesces to one re-run.
 *
 * Absent attribute (or an empty list) means an EMPTY subscription — the
 * stream never live-re-runs (a static feed), while the held-open serve and
 * the view-change re-hydrate keep working (those ride the re-run context,
 * not the scope keys).
 *
 * Lives in semitexa-core so both readers — semitexa-ssr (subscription
 * resolution) and semitexa-api (contract projection) — reach it without an
 * api↔ssr cross-dependency.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WatchScopes
{
    /** @var list<string> */
    public readonly array $scopes;

    public function __construct(string ...$scopes)
    {
        $this->scopes = array_values($scopes);
    }
}
