<?php

declare(strict_types=1);

namespace Semitexa\Core\Authorization;

/**
 * Represents the current request subject — either an authenticated user or a guest.
 * Used by the authorization layer to evaluate access policy without depending on
 * the full authentication infrastructure.
 */
interface SubjectInterface
{
    public function isGuest(): bool;

    /**
     * Returns the subject's unique identifier (user ID), or null for guests.
     */
    public function getIdentifier(): ?string;
}
