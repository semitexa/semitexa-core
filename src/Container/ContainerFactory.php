<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Psr\Container\ContainerInterface;

/**
 * @internal Bootstrap-only. Application code uses #[InjectAs*] property injection.
 *
 * Factory for creating the Semitexa DI container.
 * Build once per worker; RequestScopedContainer sets ExecutionContext per request.
 *
 * ContainerFactory::get() is an @internal runtime hook for Semitexa core and
 * low-level framework plumbing. RequestScopedContainer is created via
 * createRequestScoped() and sets ExecutionContext per request. These APIs are
 * forbidden in application modules, feature code, handlers, services, listeners,
 * repositories, and package-level business logic.
 */
class ContainerFactory
{
    private static ?SemitexaContainer $container = null;

    /**
     * Create and build the container (call once per worker).
     */
    public static function create(): SemitexaContainer
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
     * can depend on them (Environment) via #[InjectAsReadonly].
     */
    private static function registerBootstrapEntries(SemitexaContainer $container): void
    {
        $container->set(\Semitexa\Core\Environment::class, \Semitexa\Core\Environment::create());
        $container->set(\Psr\Container\ContainerInterface::class, $container);

        $root = \Semitexa\Core\Util\ProjectRoot::get();
        $container->set(\Semitexa\Core\ProjectContext::class, new \Semitexa\Core\ProjectContext(
            rootPath: $root,
            varPath: $root . '/var',
            modulesPath: $root . '/src/modules',
            packagesPath: $root . '/packages',
        ));

        $orm = new \Semitexa\Orm\OrmManager();
        $container->set(\Semitexa\Orm\OrmManager::class, $orm);
        $container->set(\Semitexa\Orm\Adapter\ConnectionPoolInterface::class, $orm->getPool());
        $container->set(\Semitexa\Orm\Adapter\DatabaseAdapterInterface::class, $orm->getAdapter());
        $container->set(\Semitexa\Orm\Transaction\TransactionManager::class, $orm->getTransactionManager());
    }

    public static function reset(): void
    {
        // No-op; container is built once per worker.
    }

    /**
     * @internal Runtime hook for Semitexa core plumbing only.
     * Application code must use #[InjectAs*] property injection.
     */
    public static function get(): SemitexaContainer
    {
        return self::create();
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
