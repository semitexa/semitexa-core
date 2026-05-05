<?php

declare(strict_types=1);

namespace Semitexa\Core\Authorization;

use Semitexa\Core\Auth\AuthSubjectType;

/**
 * Represents the current request subject — either an authenticated user/service
 * principal or a guest. Used by the authorization layer to evaluate access
 * policy without depending on the full authentication infrastructure.
 *
 * `getSubjectType()` returns User for human-facing principals and Service for
 * machine-facing principals (webhooks, API tokens, partner integrations).
 * Guests return null. The RbacDecisionCache key is composed from
 * `{subjectType:identifier}` so a User and a Service principal that happen
 * to share the same textual id never collide.
 */
interface SubjectInterface
{
    public function isGuest(): bool;

    /**
     * Returns the subject's unique identifier (user ID, service principal id,
     * webhook receiver key), or null for guests.
     */
    public function getIdentifier(): ?string;

    /**
     * Returns the subject's auth domain, or null for guests / legacy
     * unspecified cases. Required for cache-key composition and for
     * ServiceCapabilityProviderInterface routing.
     */
    public function getSubjectType(): ?AuthSubjectType;
}
