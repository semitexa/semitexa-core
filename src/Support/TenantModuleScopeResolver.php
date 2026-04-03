<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

use Semitexa\Tenancy\Context\CoroutineContextStore;

final class TenantModuleScopeResolver
{
    /**
     * @return list<string>
     */
    public static function scopesForModule(?string $moduleName): array
    {
        if ($moduleName === null || $moduleName === '' || $moduleName === 'project') {
            return [];
        }

        $normalizedModuleNames = self::normalizeModuleIdentifiers($moduleName);
        $matchedTenantIds = [];

        foreach (self::tenantModuleMap() as $tenantId => $modules) {
            foreach ($modules as $candidate) {
                if (array_intersect($normalizedModuleNames, self::normalizeModuleIdentifiers($candidate)) !== []) {
                    $matchedTenantIds[] = $tenantId;
                    break;
                }
            }
        }

        sort($matchedTenantIds);

        return array_values(array_unique($matchedTenantIds));
    }

    /**
     * @return list<string>
     */
    private static function normalizeModuleIdentifiers(string $moduleName): array
    {
        $normalized = trim(strtolower($moduleName));
        if ($normalized === '') {
            return [];
        }

        $variants = [$normalized];

        if (str_contains($normalized, '/')) {
            $hyphenated = str_replace('/', '-', $normalized);
            $variants[] = $hyphenated;

            $parts = explode('/', $normalized, 2);
            if (count($parts) === 2 && $parts[0] === 'semitexa') {
                $variants[] = $parts[1];
            }
        }

        if (str_starts_with($normalized, 'semitexa-')) {
            $variants[] = substr($normalized, strlen('semitexa-'));
        } else {
            $variants[] = 'semitexa-' . $normalized;
        }

        if (!str_contains($normalized, '/')) {
            $variants[] = str_replace('semitexa-', 'semitexa/', $normalized);
            if (!str_starts_with($normalized, 'semitexa-')) {
                $variants[] = 'semitexa/' . $normalized;
            }
        }

        return array_values(array_unique(array_filter($variants, static fn (string $value): bool => $value !== '')));
    }

    public static function scopeSignatureForModule(?string $moduleName): string
    {
        $scopes = self::scopesForModule($moduleName);

        return $scopes === [] ? 'shared' : implode(',', $scopes);
    }

    /**
     * @param array<string, mixed> $route
     */
    public static function isRouteAllowedForCurrentTenant(array $route): bool
    {
        $rawTenantScopes = $route['tenantScopes'] ?? [];
        if (!is_array($rawTenantScopes)) {
            return false;
        }

        $tenantScopes = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $rawTenantScopes,
        ), static fn (string $value): bool => $value !== ''));

        if ($tenantScopes === []) {
            return true;
        }

        $tenantId = self::currentTenantId();
        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        return in_array($tenantId, $tenantScopes, true);
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    public static function selectRoutesForCurrentTenant(array $routes): array
    {
        if ($routes === []) {
            return [];
        }

        $scoped = array_values(array_filter(
            $routes,
            static fn (array $route): bool => !empty($route['tenantScopes']) && self::isRouteAllowedForCurrentTenant($route),
        ));

        if ($scoped !== []) {
            return $scoped;
        }

        return array_values(array_filter(
            $routes,
            static fn (array $route): bool => empty($route['tenantScopes']),
        ));
    }

    private static function currentTenantId(): ?string
    {
        $context = CoroutineContextStore::get();
        if ($context === null) {
            return null;
        }

        $tenantId = $context->getTenantId();

        return $tenantId !== '' && $tenantId !== 'default' ? $tenantId : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function tenantModuleMap(): array
    {
        $map = [];

        foreach (getenv() ?: [] as $key => $value) {
            if (!preg_match('/^TENANT_([A-Z0-9_]+?)_MODULES$/', (string) $key, $matches)) {
                continue;
            }

            $tenantId = strtolower($matches[1]);
            $modules = array_values(array_filter(array_map(
                static fn (string $item): string => trim($item),
                explode(',', (string) $value),
            )));

            if ($modules !== []) {
                $map[$tenantId] = $modules;
            }
        }

        return $map;
    }
}
