<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

/**
 * The declared defaults, until a store of overrides is installed
 * (semitexa/platform-settings binds one). A percentage rollout is decided
 * per user, or per site for a guest.
 */
#[ExecutionScoped]
#[SatisfiesServiceContract(of: FeatureFlagsInterface::class)]
final class DefaultFeatureFlags implements FeatureFlagsInterface
{
    #[InjectAsReadonly]
    protected FeatureFlagRegistry $registry;

    /** Optional: a command or a job has no request, and asks forSubject() instead. */
    #[InjectAsMutable(optional: true)]
    protected AuthContextInterface $auth;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenants;

    private bool $pinned = false;
    private ?string $tenantId = null;
    private ?string $userId = null;

    public static function of(FeatureFlagRegistry $registry): self
    {
        $flags = new self();
        $flags->registry = $registry;

        return $flags;
    }

    public function isEnabled(string $flag): bool
    {
        // A guest is decided by site; on a single-site install the site is `default`.
        return $this->registry->get($flag)?->defaultFor($this->userId() ?? $this->tenantId() ?? 'default') ?? false;
    }

    public function forSubject(?string $tenantId, ?string $userId): FeatureFlagsInterface
    {
        $pinned = clone $this;
        $pinned->pinned = true;
        $pinned->tenantId = $tenantId;
        // '' is no user, as SettingsFeatureFlags reads it: taken for one, a
        // percentage rollout skipped every guest pinned this way.
        $pinned->userId = $userId !== '' ? $userId : null;

        return $pinned;
    }

    private function userId(): ?string
    {
        if ($this->pinned) {
            return $this->userId;
        }
        $id = isset($this->auth) && !$this->auth->isGuest() ? $this->auth->getUser()?->getId() : null;

        return $id !== null && $id !== '' ? $id : null;
    }

    private function tenantId(): ?string
    {
        if ($this->pinned) {
            return $this->tenantId;
        }

        return isset($this->tenants) ? TenantContextAccess::tenantId($this->tenants->tryGet()) : null;
    }
}
