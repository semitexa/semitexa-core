<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Environment;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Queue\Transport\InMemoryTransportFactory;

/**
 * Registry of queue transport factories.
 *
 * Factories are registered at boot (worker-scoped). Transport instances are
 * cached per-key so each worker reuses the same connection. The 'nats'
 * transport is registered by LedgerBootstrap when semitexa-ledger is loaded.
 *
 * @worker-scoped Initialized once per worker, read-only during requests.
 */
class QueueTransportRegistry
{
    /**
     * @var array<string, QueueTransportFactoryInterface>
     */
    private static array $factories = [];

    /**
     * @var array<string, QueueTransportInterface>
     */
    private static array $instances = [];

    private static bool $initialized = false;

    /**
     * Initialize default factories. Called once per worker (idempotent).
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        self::register('in-memory', new InMemoryTransportFactory());
        self::register('memory', new InMemoryTransportFactory());
        self::registerOptionalNatsTransport();

        self::$initialized = true;
    }

    public static function register(string $name, QueueTransportFactoryInterface $factory): void
    {
        self::$factories[strtolower($name)] = $factory;
    }

    public static function create(string $name): QueueTransportInterface
    {
        $key = strtolower($name);
        self::initialize();

        if (!isset(self::$instances[$key])) {
            if (!isset(self::$factories[$key])) {
                throw new ConfigurationException("Queue transport '{$name}' is not registered");
            }
            self::$instances[$key] = self::$factories[$key]->create();
        }

        return self::$instances[$key];
    }

    public static function has(string $name): bool
    {
        self::initialize();

        return isset(self::$factories[strtolower($name)]);
    }

    /**
     * Reset registry state. For testing only.
     */
    public static function reset(): void
    {
        self::$factories = [];
        self::$instances = [];
        self::$initialized = false;
    }

    private static function registerOptionalNatsTransport(): void
    {
        if (!self::hasNatsConfiguration()) {
            return;
        }

        $clusterRegistryClass = 'Semitexa\\Ledger\\Nats\\ClusterRegistry';
        $transportFactoryClass = 'Semitexa\\Ledger\\Queue\\NatsTransportFactory';

        if (!class_exists($clusterRegistryClass) || !class_exists($transportFactoryClass)) {
            return;
        }

        try {
            $clusters = $clusterRegistryClass::fromEnv();
            self::register('nats', new $transportFactoryClass($clusters));
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('core', 'Failed to register NATS queue transport', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function hasNatsConfiguration(): bool
    {
        $primary = Environment::getEnvValue('NATS_PRIMARY_URL');
        $fallback = Environment::getEnvValue('NATS_URL');

        return ($primary !== null && $primary !== '')
            || ($fallback !== null && $fallback !== '');
    }
}
