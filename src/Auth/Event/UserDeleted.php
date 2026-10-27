<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth\Event;

use Semitexa\Core\Attribute\AsEvent;

/**
 * An account was deleted. Dispatched by the package that owns accounts
 * (semitexa/platform-user) right after the row is gone, inside the caller's
 * transaction, so a Sync listener removes what it keeps for that user with
 * it: the user's own settings and feature flag overrides outlived the user.
 */
#[AsEvent]
final readonly class UserDeleted
{
    public function __construct(
        public string $userId,
        public ?string $tenantId = null,
    ) {}
}
