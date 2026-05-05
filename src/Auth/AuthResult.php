<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/**
 * Outcome of an authentication attempt.
 *
 * `subjectType` records WHICH auth domain produced the success — User
 * (session/customer/admin) or Service (machine/integration). The runtime
 * auth gate uses this to enforce the boundary between #[AsProtectedPayload]
 * and #[AsServicePayload]: a user-session token never satisfies a service
 * route, and a machine token never satisfies a user-session route.
 *
 * For backwards compatibility, AuthHandlers that don't yet declare a
 * subject type via the explicit factories (`successAsUser` / `successAsService`)
 * default to {@see AuthSubjectType::User}: this matches the historical
 * behaviour where every existing handler was a user-session handler.
 */
readonly class AuthResult
{
    public function __construct(
        public bool $success,
        public ?AuthenticatableInterface $user = null,
        public ?string $reason = null,
        public ?AuthSubjectType $subjectType = null,
    ) {}

    /**
     * Convenience factory that defaults to a User subject. Existing call
     * sites (`AuthResult::success($user)`) keep their current semantics —
     * every legacy handler was a user-session handler.
     */
    public static function success(AuthenticatableInterface $user): self
    {
        return self::successAsUser($user);
    }

    public static function successAsUser(AuthenticatableInterface $user): self
    {
        return new self(success: true, user: $user, subjectType: AuthSubjectType::User);
    }

    public static function successAsService(AuthenticatableInterface $principal): self
    {
        return new self(success: true, user: $principal, subjectType: AuthSubjectType::Service);
    }

    public static function failed(?string $reason = null): self
    {
        return new self(success: false, reason: $reason);
    }
}
