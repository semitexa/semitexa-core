<?php

declare(strict_types=1);

namespace Semitexa\Core\Redis;

/**
 * The worker's one Redis connection pool, and the answer to whether there is
 * one at all.
 *
 * WHY THIS EXISTS AS A SEPARATE THING. A pool can only be built when Redis is
 * configured, so registering {@see RedisConnectionPool} directly makes the
 * binding CONDITIONAL — and a conditional binding cannot be taken as a
 * dependency, because `#[InjectAs*]` on a nullable property is forbidden on
 * container-managed objects (rightly: a silently absent collaborator is exactly
 * the bug that rule prevents). So every subsystem that wanted Redis built its
 * own pool instead, and a worker ended up holding several.
 *
 * This class is registered UNCONDITIONALLY. Consumers take it as an ordinary
 * non-nullable dependency and ask it a question, instead of guessing from the
 * environment and opening sockets of their own.
 *
 * MEASURED before this existed, on the dev stack 2026-09-12: 64 connections
 * from one app container across 4 workers, 64 of 66 idle for over an hour with
 * no load — one 16-connection pool per worker, plus whatever each subsystem
 * added on top.
 */
final class RedisSharedPool
{
    private ?RedisConnectionPool $pool = null;

    /**
     * @param array{scheme?:string, host?:string, port?:int, password?:string} $config
     */
    public function __construct(
        private readonly int $size,
        private readonly array $config,
        private readonly bool $configured,
    ) {}

    /**
     * Build from the environment. `REDIS_HOST` being set is what "configured"
     * means — the same test every subsystem used to make for itself.
     */
    public static function fromEnvironment(int $size): self
    {
        $host = \Semitexa\Core\Environment::getEnvValue('REDIS_HOST');
        $configured = $host !== null && $host !== '';

        return new self(
            size: $size,
            config: [
                'scheme' => \Semitexa\Core\Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp',
                'host' => $host ?? '',
                'port' => (int) \Semitexa\Core\Environment::getEnvValue('REDIS_PORT', '6379'),
                'password' => \Semitexa\Core\Environment::getEnvValue('REDIS_PASSWORD') ?? '',
            ],
            configured: $configured,
        );
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * The pool, or null when Redis is not configured — for callers that degrade
     * rather than fail (SSE keeps working in-memory without durability).
     */
    public function poolOrNull(): ?RedisConnectionPool
    {
        if (!$this->configured) {
            return null;
        }

        return $this->pool ??= new RedisConnectionPool($this->size, $this->config);
    }

    /**
     * The pool, for callers that cannot do their job without it. Throws rather
     * than handing back null, so a misconfiguration is reported where it
     * happens instead of surfacing later as an unexplained failure.
     */
    public function pool(): RedisConnectionPool
    {
        $pool = $this->poolOrNull();
        if ($pool === null) {
            throw new \RuntimeException(
                'Redis was asked for but is not configured: REDIS_HOST is empty or unset.'
            );
        }

        return $pool;
    }
}
