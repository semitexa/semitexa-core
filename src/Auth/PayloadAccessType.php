<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/**
 * The single explicit access classification for every routable Semitexa payload.
 *
 * Set by exactly one of:
 *   #[Semitexa\Authorization\Attribute\AsPublicPayload]   → Public
 *   #[Semitexa\Authorization\Attribute\AsProtectedPayload] → Protected
 *   #[Semitexa\Authorization\Attribute\AsServicePayload]  → Service
 *
 * There is no implicit default. A routable payload that lacks one of those
 * attributes is rejected by PayloadAccessPolicyResolver during boot/discovery.
 *
 * Public  — anonymous access; no user or service authentication required.
 * Protected — user-facing endpoint; requires user/session/admin authentication.
 * Service  — machine-to-machine endpoint (signed webhook receivers, machine
 *           tokens, partner APIs, NATS/HTTP bridges); requires service-level
 *           authentication. NOT to be confused with "publicly reachable":
 *           a webhook URL is public on the internet but the access type is
 *           Service because the payload is authenticated by a signature or
 *           machine credential.
 */
enum PayloadAccessType: string
{
    case Public = 'public';
    case Protected = 'protected';
    case Service = 'service';

    /** Public payloads bypass the auth gate entirely. */
    public function requiresAuthentication(): bool
    {
        return $this !== self::Public;
    }

    /** Service payloads are authenticated by machine credentials, not user sessions. */
    public function isServiceAccess(): bool
    {
        return $this === self::Service;
    }
}
