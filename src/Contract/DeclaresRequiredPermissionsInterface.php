<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * A route attribute that knows the permissions its route requires — a CRUD
 * screen declared with `permission: 'content'` requires `content.read`
 * without also carrying #[RequiresPermission] by hand. The authorization
 * policy adds these to any #[RequiresPermission] on the payload.
 */
interface DeclaresRequiredPermissionsInterface
{
    /**
     * @param class-string $payloadClass the class the attribute sits on
     * @return list<string>
     */
    public function requiredPermissions(string $payloadClass): array;
}
