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
 * with `filter: true`. This declaration introduces the marker and the advertisement; the
 * runtime that HONORS a view-change command (the inbound command intake + the
 * filter-only re-run override) lives elsewhere — nothing here changes serving.
 *
 * Only filter-shaped inputs carry this marker. Transport / identity metadata
 * (e.g. a held-open stream's session id) must NOT be marked: it is not a filter
 * and must never ride a view-change command (the R2 anti-poisoning invariant —
 * a view-change override touches filter setters only, never identity).
 *
 * Lives in semitexa-core and references no authorization type, preserving
 * `semitexa-core → (no upward dep on) semitexa-authorization`.
 *
 * Detection is PROPERTY-based: {@see \Semitexa\Core\Http\PayloadMetadataReflector}
 * and the view-change override scan a DTO's properties. The attribute targets
 * `TARGET_PROPERTY | TARGET_PARAMETER` so it may sit on a plain property OR on a
 * PROMOTED constructor parameter (which also becomes a property) — both are
 * detected. A non-promoted constructor parameter is NOT a property and is NOT
 * detected; declare filter fields as properties (promoted or plain).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class LiveFilterParam
{
}
