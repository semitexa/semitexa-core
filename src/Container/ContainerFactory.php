<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Psr\Container\ContainerInterface;

/**
 * Factory for creating the Semitexa DI container.
 * Build once per worker; RequestScopedContainer sets RequestContext per request.
 */
class ContainerFactory
{
    private static ?SemitexaContainer $container = null;
    private static ?RequestScopedContainer $requestScopedContainerInstance = null;

    /**
     * Create and build the container (call once per worker).
     */
    public static function create(): ContainerInterface
    {
        if (self::$container === null) {
            $container = new SemitexaContainer();
            self::registerBootstrapEntries($container);
            $container->build();
            self::$container = $container;
        }
        return self::$container;
    }

    /**
     * Register bootstrap entries before build() so that contract implementations (e.g. AsyncJsonLogger)
     * can depend on them (Environment) in the constructor.
     */
    private static function registerBootstrapEntries(SemitexaContainer $container): void
    {
        $container->set(\Semitexa\Core\Environment::class, \Semitexa\Core\Environment::create());
    }

    public static function reset(): void
    {
        // No-op; container is built once per worker.
    }

    /**
     * Get the singleton container instance.
     */
    public static function get(): ContainerInterface
    {
        return self::create();
    }

    /**
     * Get request-scoped container wrapper (singleton per worker).
     * Application sets Session/Cookie/Request and RequestContext here; handlers are resolved via container with context.
     */
    public static function getRequestScoped(): RequestScopedContainer
    {
        if (self::$requestScopedContainerInstance === null) {
            self::$requestScopedContainerInstance = new RequestScopedContainer(self::create());
        }
        return self::$requestScopedContainerInstance;
    }

    /**
     * Create a new RequestScopedContainer instance (not singleton).
     * Use this in Swoole request handlers to ensure coroutine safety —
     * each concurrent request gets its own request-scoped cache.
     */
    public static function createRequestScoped(): RequestScopedContainer
    {
        return new RequestScopedContainer(self::create());
    }
}
