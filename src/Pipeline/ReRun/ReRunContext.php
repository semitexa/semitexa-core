<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline\ReRun;

use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextInterface;

/**
 * The worker-local, never-serialized state needed to re-run one frozen authorized
 * request (Track R · R2; design §B.1/§B.2/§C "DTO registry" tier).
 *
 * A stream is a *frozen authorized request*. What is frozen is the **immutable
 * block + session reference**, NOT the subject and NOT a precomputed frame:
 *
 *  - {@see $cachedDto} — the hydrated Payload DTO. It supplies ONLY the *unchanged
 *    request shape* (filters etc.); it is NEVER an identity source. See the
 *    anti-poisoning invariant on {@see getCachedDto()}.
 *  - {@see $requestSnapshot} — the auth-bearing request snapshot (cookies / session
 *    id / query) captured at connect; {@see rebuildRequest()} rebuilds a real
 *    {@see Request} from it so identity is **re-resolved from the live session each
 *    run** (design §B.1).
 *  - {@see $tenantContext} — the immutable tenant context captured at connect,
 *    re-established into the request-scoped store on every re-run.
 *  - {@see $subjectRef} — the frozen subject reference from the immutable block. It
 *    is the *anchor* a re-auth compares the live session's subject against; it is
 *    NOT read from the DTO.
 *
 * Because the DTO registry is worker-local and never crosses a worker or a wire
 * (design §C: `dispatch_mode 2` pins the fd to the owning worker for life), this VO
 * holds the rich live {@see TenantContextInterface} object directly rather than a
 * serialized blob — the blob form belongs to the cross-worker `subscriptionTable`
 * (R1), not here. Re-establishment is therefore a store `set()`, the core-local
 * equivalent of `TenantContext::fromQueuePayload()`.
 */
final class ReRunContext
{
    /**
     * @param array{
     *     method?: string,
     *     uri?: string,
     *     headers?: array<string, string>,
     *     query?: array<array-key, string|array<mixed>>,
     *     post?: array<array-key, string|array<mixed>>,
     *     server?: array<string, mixed>,
     *     cookies?: array<string, string>,
     *     content?: ?string,
     *     files?: array<string, mixed>,
     * } $requestSnapshot
     * @param array<string, mixed>|null $broadcastProperties
     */
    public function __construct(
        private readonly object $cachedDto,
        private readonly DiscoveredRoute $route,
        private readonly array $requestSnapshot,
        private readonly string $sessionId,
        private readonly string $subjectRef,
        private readonly ?TenantContextInterface $tenantContext = null,
        private readonly ?array $broadcastProperties = null,
    ) {}

    /**
     * The hydrated request DTO.
     *
     * ANTI-POISONING INVARIANT (the security core, design §A.2/§B.3): this object
     * supplies only the *unchanged request shape* — filters and the like. It is
     * NEVER an identity or authorization input. Identity is re-resolved from
     * {@see rebuildRequest()} (the live session) + {@see $subjectRef} (the immutable
     * block) on every run, so mutating the cached DTO's fields cannot change who the
     * re-run authorizes as.
     */
    public function getCachedDto(): object
    {
        return $this->cachedDto;
    }

    public function getRoute(): DiscoveredRoute
    {
        return $this->route;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * The frozen subject reference from the immutable block — the anchor a re-auth
     * compares the live session's subject against (logout / identity change yields a
     * mismatch → TERMINATE). Read from the immutable block, never from the DTO.
     */
    public function getSubjectRef(): string
    {
        return $this->subjectRef;
    }

    /**
     * The immutable tenant context captured at connect, to be re-established into
     * the request-scoped tenant store before each re-run. Null when the stream ran
     * under the default/guest tenant (re-establishment is then a no-op).
     */
    public function getTenantContext(): ?TenantContextInterface
    {
        return $this->tenantContext;
    }

    /**
     * Rebuild the auth-bearing {@see Request} from the connect-time snapshot. The
     * session cookie carried here is the load-bearing identity source for the
     * re-auth — never the cached DTO.
     */
    public function rebuildRequest(): Request
    {
        $s = $this->requestSnapshot;

        return new Request(
            method: $s['method'] ?? 'GET',
            uri: $s['uri'] ?? '/',
            headers: $s['headers'] ?? [],
            query: $s['query'] ?? [],
            post: $s['post'] ?? [],
            server: $s['server'] ?? [],
            cookies: $s['cookies'] ?? [],
            content: $s['content'] ?? null,
            files: $s['files'] ?? [],
        );
    }

    /**
     * Strategy-C seam (design §B.4) — first-class, intentionally unimplemented.
     *
     * Strategy A (full-chain re-run) is the default and only path today. A future
     * Strategy C (in-memory pre-filter) can short-circuit a full re-execution when
     * the changed rows can be diffed against these broadcast properties in memory.
     * The hook is carried from day one because retrofitting it later is expensive;
     * it returns null/empty today, so the re-runner always falls through to
     * Strategy A.
     *
     * @return array<string, mixed>|null
     */
    public function getBroadcastProperties(): ?array
    {
        return $this->broadcastProperties;
    }
}
