<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

/**
 * The provable authorization-gate model an SSE endpoint declares.
 *
 * An SSE stream re-runs (or holds open) outside the normal request/response
 * cycle, so the access attribute alone (`#[AsPublicPayload]` / `…Protected` /
 * `…Service`) does not say *how* the open stream is gated. This enum names the
 * three real gate families that exist in the framework today so the boot guard
 * in {@see \Semitexa\Core\Discovery\AttributeDiscovery} can prove that every
 * `transport: TransportType::Sse` endpoint has positively declared its gate —
 * turning a silently-ungated public stream into an explicit boot failure.
 *
 * This lives in semitexa-core and references no authorization type, preserving
 * `semitexa-core → (no upward dep on) semitexa-authorization`. It is a field on
 * {@see AbstractPayloadRoute} (read in the existing IS_INSTANCEOF discovery
 * pass), not a separate attribute — same precedent as `invalidatedBy`.
 *
 * The declaration is an auditable promise the guard proves is *present*; it does
 * not, and at boot cannot, prove the handler actually honors it (the in-handler
 * token / admit check is imperative). Closing that residual behavior gap is a
 * handler-level concern, not a discovery one.
 */
enum SseGateModel
{
    /**
     * Re-authorized per request/tick against the session subject via the
     * framework AuthorizationListener (the provable gate). A Subject-gated
     * stream cannot be `#[AsPublicPayload]`: a public endpoint has no subject
     * to re-authorize. Used by subject-scoped streams (e.g. grid live streams).
     */
    case Subject;

    /**
     * Gated in-handler by a signed channel token (platform-ui's HMAC
     * `UiSseChannelToken`, verified before any stream lifecycle starts). The
     * token carries authenticity + scope but no subject, so this is valid on a
     * public route — the gate lives in the handler, not the auth pipeline.
     * Used by `/__ui/stream`.
     */
    case ChannelToken;

    /**
     * Gated in-server by the shared bearer/session admit chain in
     * `AsyncResourceSseServer::handleSse` (authenticated session OR safe bearer
     * session id OR the guest-safe deferred door, plus same-origin and
     * connection caps). Valid on a public route — the gate is in-server. Used by
     * `/sse` and `/__semitexa_kiss`, which share one code path.
     */
    case BearerSession;
}
