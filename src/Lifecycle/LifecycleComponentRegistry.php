<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthBootstrapperFactoryInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Tenant\TenancyBootstrapperFactoryInterface;
use Semitexa\Core\Tenant\TenancyBootstrapperInterface;
use Semitexa\Locale\Context\LocaleManager;
use Semitexa\Locale\LocaleBootstrapper;

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
        ContainerInterface $container,
        ?ClassDiscovery $classDiscovery = null,
        ?EventDispatcherInterface $events = null,
    ): ?TenancyBootstrapperInterface {
        if (!$this->tenancyAvailable) {
            return null;
        }

        if (!$container->has(TenancyBootstrapperFactoryInterface::class)) {
            $bootstrapperClass = 'Semitexa\\Tenancy\\TenancyBootstrapper';
            if (!class_exists($bootstrapperClass)) {
                return null;
            }

            $tenantContextStore = $container->get(\Semitexa\Core\Tenant\TenantContextStoreInterface::class);
            $bootstrapper = new $bootstrapperClass($tenantContextStore, $classDiscovery, $events);

            return $bootstrapper instanceof TenancyBootstrapperInterface ? $bootstrapper : null;
        }

        /** @var TenancyBootstrapperFactoryInterface $factory */
        $factory = $container->get(TenancyBootstrapperFactoryInterface::class);

        return $factory->create($container);
    }

    /**
     * Create an AuthBootstrapperInterface via the factory binding contributed by
     * semitexa-auth. Returns null if the auth package is not active, or if the
     * factory binding is not registered in the container.
     */
    public function createAuthBootstrapper(
        ContainerInterface $container,
        RequestScopedContainer $requestScopedContainer,
    ): ?AuthBootstrapperInterface {
        if (!$this->authAvailable) {
            return null;
        }

        if (!$container->has(AuthBootstrapperFactoryInterface::class)) {
            $bootstrapperClass = 'Semitexa\\Auth\\AuthBootstrapper';
            if (!class_exists($bootstrapperClass)) {
                return null;
            }

            $classDiscovery = $container->has(ClassDiscovery::class)
                ? $container->get(ClassDiscovery::class)
                : null;
            $authContext = $container->has(\Semitexa\Core\Auth\AuthContextInterface::class)
                ? $container->get(\Semitexa\Core\Auth\AuthContextInterface::class)
                : null;
            $logger = $container->has(\Semitexa\Core\Log\LoggerInterface::class)
                ? $container->get(\Semitexa\Core\Log\LoggerInterface::class)
                : null;

            $bootstrapper = new $bootstrapperClass(
                container: $container,
                requestScopedContainer: $requestScopedContainer,
                classDiscovery: $classDiscovery,
                authContext: $authContext,
                logger: $logger,
            );

            return $bootstrapper instanceof AuthBootstrapperInterface ? $bootstrapper : null;
        }

        /** @var AuthBootstrapperFactoryInterface $factory */
        $factory = $container->get(AuthBootstrapperFactoryInterface::class);

        return $factory->create($container, $requestScopedContainer);
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
