<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant\Scope;

use Semitexa\Core\Tenant\TenantContextInterface;

final class NullTenantScope implements TenantScopeInterface
{
    public function apply(object $queryBuilder, TenantContextInterface $context): void
    {
    }
}
