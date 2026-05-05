<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/**
 * Domain of an authenticated principal.
 *
 *   User    — the authenticated principal is a user/session/customer/admin.
 *             Produced by user-facing AuthHandlers (e.g. SessionAuthHandler).
 *
 *   Service — the authenticated principal is a machine/integration/partner
 *             (signed webhook source, partner API caller, deployment runner,
 *             internal NATS bridge). Produced by service-facing AuthHandlers
 *             (e.g. MachineAuthHandler in semitexa-api).
 *
 * Carried on AuthResult so PreHydrationAuthGate and AuthorizationListener can
 * enforce that a #[AsProtectedPayload] route only accepts user-domain auth and
 * a #[AsServicePayload] route only accepts service-domain auth. The two
 * domains are disjoint by default — a service token never satisfies a user
 * route, and a user session never satisfies a service route.
 *
 * Maps 1-1 against PayloadAccessType for the non-Public values; Public
 * payloads bypass the gate entirely and never read this enum.
 */
enum AuthSubjectType: string
{
    case User = 'user';
    case Service = 'service';

    /**
     * The PayloadAccessType this subject type satisfies. Used by the runtime
     * auth gate so the compatibility check stays in one place.
     */
    public function satisfies(PayloadAccessType $access): bool
    {
        return match ($access) {
            PayloadAccessType::Public    => true,
            PayloadAccessType::Protected => $this === self::User,
            PayloadAccessType::Service   => $this === self::Service,
        };
    }
}
