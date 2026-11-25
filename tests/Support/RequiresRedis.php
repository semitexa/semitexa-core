<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Support;

use Semitexa\Core\Environment;

/**
 * The real Redis an integration test is about, reached as the application
 * reaches it.
 */
trait RequiresRedis
{
    private static function reachableRedisHost(): string
    {
        // Not configured is a skip; configured and silent fails: a skip there
        // left a stack that could not reach its Redis green over these checks.
        // Read as the application reads it (RedisSharedPool::fromEnvironment):
        // a REDIS_HOST only in .env is configured, not unset.
        $host = Environment::getEnvValue('REDIS_HOST');
        if ($host === null || $host === '') {
            self::markTestSkipped('REDIS_HOST not configured — the test requires real Redis');
        }
        $probe = @fsockopen($host, (int) Environment::getEnvValue('REDIS_PORT', '6379'), $errno, $errstr, 1.0);
        if ($probe === false) {
            self::fail("REDIS_HOST={$host} is configured, but nothing answers on its port: {$errstr}");
        }
        fclose($probe);

        return $host;
    }

    /**
     * The connection as the application makes it (RedisSharedPool::fromEnvironment):
     * scheme, host, port and password, so a protected or non-default Redis
     * configured in .env is reached the same way.
     *
     * @return array{scheme: string, host: string, port: int, password: string}
     */
    private static function redisConfig(string $host): array
    {
        return [
            'scheme' => Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp',
            'host' => $host,
            'port' => (int) Environment::getEnvValue('REDIS_PORT', '6379'),
            'password' => Environment::getEnvValue('REDIS_PASSWORD') ?? '',
        ];
    }
}
