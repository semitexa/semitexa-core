<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Exception\ConfigurationException;
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

    /**
     * Reset registry state. For testing only.
     */
    public static function reset(): void
    {
        self::$factories = [];
        self::$instances = [];
        self::$initialized = false;
    }
}
