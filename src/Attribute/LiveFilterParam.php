<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Marks a payload field as a LIVE FILTER PARAM — a filter / paging / sort input
 * that, in the intended grid interaction model, is changed via a view-change
 * COMMAND sent over the already-open SSE stream (Mode 3 / `sse-update`), NOT by
 * tearing down and re-opening the stream.
 *
 * This is a DECLARATION ONLY. It is the marker the OPTIONS metadata reflector
 * ({@see \Semitexa\Core\Http\PayloadMetadataReflector}) already looks for: its
 * presence on any field flips `hasLiveFilterParam()` true, so `deriveModes()`
 * adds `sse-update` to the advertised `modes` and each marked field is reported
 * with `filter: true`. Phase 1 introduces the marker and the advertisement; the
 * runtime that HONORS a view-change command (the inbound command intake + the
 * filter-only re-run override) is Phase 2 — nothing here changes serving.
 *
 * Only filter-shaped inputs carry this marker. Transport / identity metadata
 * (e.g. a held-open stream's session id) must NOT be marked: it is not a filter
 * and must never ride a view-change command (the R2 anti-poisoning invariant —
 * a view-change override touches filter setters only, never identity).
 *
 * Lives in semitexa-core and references no authorization type, preserving
 * `semitexa-core → (no upward dep on) semitexa-authorization`. Allowed on both
 * properties and constructor parameters so a DTO may declare its filter fields
 * either way; the reflector scans properties.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class LiveFilterParam
{
}
