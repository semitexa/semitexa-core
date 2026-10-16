<?php

declare(strict_types=1);

namespace Semitexa\Core\Redis;

use Predis\Client;
use Predis\ClientInterface;
use Predis\Connection\ConnectionException;

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
    private RedisCircuitBreaker $circuit;

    /** @var \WeakMap<ClientInterface, float> when each client last went back into the pool */
    private \WeakMap $returnedAt;

    /**
     * A client idle at least this long is PINGed before use. After a Redis
     * restart every pooled socket is dead, and the first command on each would
     * fail — while the breaker is open nothing uses them, so by the time Redis
     * is back they have all been idle longer than this.
     */
    private const IDLE_PING_AFTER_SECONDS = 1.0;

    /**
     * All bounded so a slow/broken Redis or a momentarily-exhausted pool can never
     * suspend a Swoole coroutine forever — an indefinite blocking read or an
     * unbounded channel pop is what triggers the "all coroutines asleep - deadlock"
     * worker crash behind held-open SSE feeds.
     */
    private const CONNECT_TIMEOUT_SECONDS = 3.0;
    private const READ_WRITE_TIMEOUT_SECONDS = 5.0;
    private const BORROW_TIMEOUT_SECONDS = 2.0;
    private const RETURN_TIMEOUT_SECONDS = 0.05;

    /**
     * @param int $size Number of connections in the pool
     * @param array{scheme?:string, host?:string, port?:int, password?:string} $config Redis connection params
     */
    public function __construct(int $size, array $config, ?RedisCircuitBreaker $circuit = null)
    {
        $this->circuit = $circuit ?? new RedisCircuitBreaker();
        $this->returnedAt = new \WeakMap();
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

        $pool = new \Swoole\Coroutine\Channel($this->size);

        for ($i = 0; $i < $this->size; $i++) {
            $pool->push($this->createClient());
        }

        $this->pool = $pool;
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
            // Bounded borrow: an unbounded pop() on a momentarily-exhausted pool
            // (e.g. every connection held by a stalled read) suspends this
            // coroutine with no producer to wake it — a worker-killing deadlock.
            // On timeout, hand out a fresh (over-cap) client so the caller can
            // proceed; put() drops it back if the pool is already full.
            $client = $this->pool->pop(self::BORROW_TIMEOUT_SECONDS);
            if (!$client instanceof ClientInterface) {
                return $this->createClient();
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
        $this->returnedAt[$client] = microtime(true);
        if ($this->pool !== null) {
            // Non-blocking return: if the pool is already full (an over-cap client
            // created by a timed-out get()), drop this one instead of blocking on
            // a full channel — a blocking push would be the same deadlock, inverted.
            if (!$this->pool->push($client, self::RETURN_TIMEOUT_SECONDS)) {
                try {
                    $client->disconnect();
                } catch (\Throwable) {
                    // Ignore — the client is being discarded anyway.
                }
            }
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
        // While Redis is unreachable every call fails fast instead of paying a
        // connect (or DNS) timeout, so an outage costs callers nothing extra.
        // The callback runs exactly once: replaying it after a lost response
        // could apply a non-idempotent command (INCR, RPUSH) twice.
        return $this->circuit->call(fn (): mixed => $this->runWithConnection($callback));
    }

    /**
     * A borrowed client, checked with a PING when it sat in the pool long
     * enough for its socket to have died (a Redis restart, a proxy closing
     * idle connections). A dead one is replaced, together with every other
     * idle client — after a restart they are all dead — by a fresh client.
     * PING is safe to repeat; the caller's commands are never replayed.
     */
    private function usableClient(): ClientInterface
    {
        $client = $this->get();
        $idleSince = $this->returnedAt[$client] ?? null;
        if ($idleSince === null
            || !$client->getConnection()->isConnected()
            || microtime(true) - $idleSince < self::IDLE_PING_AFTER_SECONDS
        ) {
            return $client;
        }

        try {
            $client->ping();

            return $client;
        } catch (ConnectionException) {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // A dead socket cannot be closed cleanly; it is dropped either way.
            }
            $this->discardIdleClients();

            return $this->createClient();
        } catch (\Throwable $e) {
            // Redis answered (-LOADING, say): the socket is fine. Back into
            // the pool, or a pooled client is lost until the worker restarts.
            $this->put($client);

            throw $e;
        }
    }

    /** Disconnect every idle pooled client and put fresh (unconnected) ones in their place. */
    private function discardIdleClients(): void
    {
        if ($this->pool === null) {
            $this->cliFallback = null;

            return;
        }
        $idleCount = $this->pool->length();
        for ($i = is_int($idleCount) ? $idleCount : 0; $i > 0; $i--) {
            $idle = $this->pool->pop(self::RETURN_TIMEOUT_SECONDS);
            if (!$idle instanceof ClientInterface) {
                break;
            }
            try {
                $idle->disconnect();
            } catch (\Throwable) {
                // A dead socket cannot be closed cleanly; it is dropped either way.
            }
            if (!$this->pool->push($this->createClient(), self::RETURN_TIMEOUT_SECONDS)) {
                break;
            }
        }
    }

    public function isCircuitOpen(): bool
    {
        return $this->circuit->isOpen();
    }

    /**
     * @template T
     * @param callable(ClientInterface): T $callback
     * @return T
     */
    private function runWithConnection(callable $callback): mixed
    {
        $client = $this->usableClient();
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
                    // Bounded, like put(): when the borrowed client was an
                    // over-cap extra the channel is already full, and an
                    // unbounded push would suspend this coroutine forever.
                    // Drop the replacement instead.
                    $replacement = $this->createClient();
                    if (!$this->pool->push($replacement, self::RETURN_TIMEOUT_SECONDS)) {
                        try {
                            $replacement->disconnect();
                        } catch (\Throwable) {
                            // Ignore — the replacement is being discarded anyway.
                        }
                    }
                } catch (\Throwable) {
                    // Pool might be closed or replacement creation failed; ignore
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
            // Bounded connect + read/write timeouts: an indefinite blocking read
            // (Redis slow/restarted, half-open socket) is the classic Swoole
            // worker deadlock. On timeout Predis throws → withConnection() discards
            // the connection and creates a fresh one, so the pool self-heals.
            'timeout'            => self::CONNECT_TIMEOUT_SECONDS,
            'read_write_timeout' => self::READ_WRITE_TIMEOUT_SECONDS,
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
