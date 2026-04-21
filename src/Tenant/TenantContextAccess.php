<?php

declare(strict_types=1);

namespace Semitexa\Core\Tenant;

use Semitexa\Core\Tenant\Layer\OrganizationLayer;

final class TenantContextAccess
{
    public static function tenantId(?TenantContextInterface $context): ?string
    {
        if (self::isDefault($context)) {
            return null;
        }

        $tenantId = self::tenantIdOrDefault($context);

        return $tenantId !== '' && $tenantId !== 'default' ? $tenantId : null;
    }

    public static function tenantIdOrDefault(?TenantContextInterface $context): string
    {
        if ($context === null) {
            return 'default';
        }

        if (method_exists($context, 'getTenantId')) {
            $tenantId = $context->getTenantId();

            if (is_string($tenantId)) {
                $tenantId = trim($tenantId);

                return $tenantId !== '' ? $tenantId : 'default';
            }

            if ($tenantId instanceof \Stringable) {
                $normalizedTenantId = trim((string) $tenantId);

                return $normalizedTenantId !== '' ? $normalizedTenantId : 'default';
            }

            if (is_scalar($tenantId) && !is_bool($tenantId)) {
                $normalizedTenantId = trim((string) $tenantId);

                return $normalizedTenantId !== '' ? $normalizedTenantId : 'default';
            }
        }

        $organization = $context->getLayer(new OrganizationLayer());
        if ($organization === null) {
            return 'default';
        }

        $tenantId = trim($organization->rawValue());

        return $tenantId !== '' ? $tenantId : 'default';
    }

    public static function isDefault(?TenantContextInterface $context): bool
    {
        if ($context === null) {
            return true;
        }

        if (method_exists($context, 'isDefault')) {
            $isDefault = $context->isDefault();

            if (is_bool($isDefault)) {
                return $isDefault;
            }
        }

        return self::tenantIdOrDefault($context) === 'default';
    }
}
