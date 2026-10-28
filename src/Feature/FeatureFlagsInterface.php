<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

/**
 * Is a feature on? For the current request's site and user, or for an explicit
 * subject. Never throws: an undeclared flag, or a store that cannot answer, is
 * the declared default (off when undeclared).
 */
interface FeatureFlagsInterface
{
    public function isEnabled(string $flag): bool;

    /** The same question for a given site and user (a job, a command, an admin screen). */
    public function forSubject(?string $tenantId, ?string $userId): self;
}
