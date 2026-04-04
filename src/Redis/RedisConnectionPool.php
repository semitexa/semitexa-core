<?php

declare(strict_types=1);

namespace Semitexa\Core\Redis;

use Predis\Client;
use Predis\ClientInterface;

/**
 * Coroutine-safe Redis connection pool backed by Swoole\Coroutine\Channel.
 *
 * Each Predis Client in the pool owns an exclusive TCP connection.
 * Coroutines borrow a client via get(), use it for one or more commands,
 * then return it via put(). This prevents protocol interleaving.
 *
 * In non-Swoole environments (CLI, tests), falls back to a single shared client.
 */
final class RedisConnectionPool
{
    private ?\Swoole\Coroutine\Channel $pool = null;
    private ?ClientInterface $cliFallback = null;

    private string $scheme;
    private string $host;
    private int $port;
    private string $password;
    private int $size;

    /**
     * @param int $size Number of connections in the pool
     * @param array{scheme?:string, host?:string, port?:int, password?:string} $config Redis connection params
     */
    public function __construct(int $size, array $config)
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Redis pool size must be at least 1');
        }

        $this->size = $size;
        $this->scheme = $config['scheme'] ?? 'tcp';
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = $config['port'] ?? 6379;
        $this->password = $config['password'] ?? '';
    }

    /**
     * Initialize the pool. Must be called inside a Swoole worker (after fork).
     * Safe to call multiple times (idempotent).
     */
    public function boot(): void
    {
        if ($this->pool !== null) {
            return;
        }

        if (!$this->inSwoole() || \Swoole\Coroutine::getCid() < 0) {
            return;
        }

        $this->pool = new \Swoole\Coroutine\Channel($this->size);

        for ($i = 0; $i < $this->size; $i++) {
            $this->pool->push($this->createClient());
        }
    }

    /**
     * Borrow a Redis client from the pool.
     * Blocks the coroutine until a connection is available.
     */
    public function get(): ClientInterface
    {
        if ($this->pool === null && $this->inSwoole() && \Swoole\Coroutine::getCid() >= 0) {
            $this->boot();
        }

        if ($this->pool !== null) {
            $client = $this->pool->pop();
            if (!$client instanceof ClientInterface) {
                throw new \RuntimeException('Redis pool exhausted or closed');
            }
            return $client;
        }

        // CLI fallback: single shared client
        return $this->cliFallback ??= $this->createClient();
    }

    /**
     * Return a Redis client to the pool after use.
     */
    public function put(ClientInterface $client): void
    {
        if ($this->pool !== null) {
            $this->pool->push($client);
            return;
        }

        // CLI: nothing to return
    }

    /**
     * Execute a callback with a borrowed connection, auto-returning on completion.
     *
     * @template T
     * @param callable(ClientInterface): T $callback
     * @return T
     */
    public function withConnection(callable $callback): mixed
    {
        $client = $this->get();
        try {
            return $callback($client);
        } catch (\Throwable $e) {
            // On error, discard the potentially broken connection and create a fresh one
            if ($this->pool !== null) {
                try {
                    $client->disconnect();
                } catch (\Throwable) {
                    // Ignore disconnect failures for broken clients.
                }

                try {
                    $this->pool->push($this->createClient());
                } catch (\Throwable) {
                    // Pool might be closed; ignore
                }
            } else {
                $this->cliFallback = null;
            }
            throw $e;
        } finally {
            // Only return if no exception (exception path creates a new one above)
            if (!isset($e)) {
                $this->put($client);
            }
        }
    }

    public function getSize(): int
    {
        return $this->size;
    }

    private function createClient(): ClientInterface
    {
        $params = [
            'scheme' => $this->scheme,
            'host'   => $this->host,
            'port'   => $this->port,
        ];

        if ($this->password !== '') {
            $params['password'] = $this->password;
        }

        return new Client($params);
    }

    private function inSwoole(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine\Channel::class);
    }
}
