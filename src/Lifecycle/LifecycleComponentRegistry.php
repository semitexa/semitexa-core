<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Auth\AuthBootstrapper;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Locale\Context\LocaleManager;
use Semitexa\Locale\LocaleBootstrapper;
use Semitexa\Tenancy\TenancyBootstrapper;

/**
 * Centralizes detection and creation of optional lifecycle bootstrappers.
 *
 * Replaces scattered class_exists() checks in Application with a single
 * source of truth for which optional packages are available and how to
 * construct their bootstrappers.
 *
 * Registered as a readonly service during RegistryBuildPhase.
 *
 * @internal Used by Application to construct lifecycle phase dependencies.
 */
final class LifecycleComponentRegistry
{
    private bool $tenancyAvailable;
    private bool $authAvailable;
    private bool $localeAvailable;

    public function __construct(private readonly ModuleRegistry $moduleRegistry)
    {
        $this->tenancyAvailable = $this->moduleRegistry->isActive('semitexa-tenancy');
        $this->authAvailable = $this->moduleRegistry->isActive('semitexa-auth');
        $this->localeAvailable = $this->moduleRegistry->isActive('semitexa-locale');
    }

    public function isTenancyAvailable(): bool
    {
        return $this->tenancyAvailable;
    }

    public function isAuthAvailable(): bool
    {
        return $this->authAvailable;
    }

    public function isLocaleAvailable(): bool
    {
        return $this->localeAvailable;
    }

    /**
     * Create a TenancyBootstrapper instance.
     * Returns null if the tenancy package is not available.
     */
    public function createTenancyBootstrapper(
        ?ClassDiscovery $classDiscovery = null,
        ?EventDispatcherInterface $events = null,
    ): ?TenancyBootstrapper {
        if (!$this->tenancyAvailable) {
            return null;
        }
        return new TenancyBootstrapper(
            classDiscovery: $classDiscovery,
            events: $events,
        );
    }

    /**
     * Create an AuthBootstrapper instance.
     * Returns null if the auth package is not available.
     */
    public function createAuthBootstrapper(
        ContainerInterface $container,
        RequestScopedContainer $requestScopedContainer,
        ?ClassDiscovery $classDiscovery = null,
        ?EventDispatcherInterface $events = null,
    ): ?AuthBootstrapper {
        if (!$this->authAvailable) {
            return null;
        }
        return new AuthBootstrapper(
            container: $container,
            classDiscovery: $classDiscovery,
            events: $events,
            requestScopedContainer: $requestScopedContainer,
        );
    }

    /**
     * Create a LocaleBootstrapper instance.
     * Returns null if the locale package is not available.
     */
    public function createLocaleBootstrapper(
        ?EventDispatcherInterface $events = null,
    ): ?LocaleBootstrapper {
        if (!$this->localeAvailable) {
            return null;
        }
        return new LocaleBootstrapper(
            new LocaleManager(),
            events: $events,
        );
    }
}
