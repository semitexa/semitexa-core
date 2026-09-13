<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Redis\RedisSharedPool;

/**
 * The owner has to be registrable unconditionally, which means it must be
 * constructible and answerable when Redis is NOT configured — that is the whole
 * reason it exists rather than the pool being bound directly.
 *
 * Nothing here connects: the pool fills its channel lazily inside a coroutine,
 * so constructing one touches no socket.
 */
final class RedisSharedPoolTest extends TestCase
{
    private function configured(int $size = 4): RedisSharedPool
    {
        return new RedisSharedPool(
            size: $size,
            config: ['scheme' => 'tcp', 'host' => 'redis.invalid', 'port' => 6379, 'password' => ''],
            configured: true,
        );
    }

    private function unconfigured(): RedisSharedPool
    {
        return new RedisSharedPool(size: 4, config: [], configured: false);
    }

    #[Test]
    public function an_unconfigured_owner_still_exists_and_says_so(): void
    {
        $shared = $this->unconfigured();

        self::assertFalse($shared->isConfigured());
        self::assertNull($shared->poolOrNull(), 'callers that degrade get null, not an exception');
    }

    #[Test]
    public function asking_an_unconfigured_owner_for_a_pool_fails_where_it_happens(): void
    {
        $shared = $this->unconfigured();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/REDIS_HOST/');
        $shared->pool();
    }

    #[Test]
    public function a_configured_owner_hands_out_one_pool_and_always_the_same_one(): void
    {
        $shared = $this->configured();

        $first = $shared->pool();
        $second = $shared->pool();
        $third = $shared->poolOrNull();

        self::assertInstanceOf(RedisConnectionPool::class, $first);
        self::assertSame($first, $second, 'a second ask must not open a second pool');
        self::assertSame($first, $third, 'the degrading accessor must hand back the same pool');
    }

    #[Test]
    public function the_pool_is_built_at_the_configured_size(): void
    {
        self::assertSame(7, $this->configured(size: 7)->pool()->getSize());
    }

    #[Test]
    public function nothing_is_built_until_something_asks(): void
    {
        $shared = $this->configured();

        $ref = new \ReflectionClass($shared);
        $prop = $ref->getProperty('pool');
        $prop->setAccessible(true);

        self::assertNull($prop->getValue($shared), 'registering the owner must not open connections');

        $shared->pool();
        self::assertNotNull($prop->getValue($shared));
    }

    #[Test]
    public function the_environment_factory_reads_redis_host_as_the_test_of_configuration(): void
    {
        $previous = getenv('REDIS_HOST');

        try {
            putenv('REDIS_HOST=');
            self::assertFalse(SharedRedisPoolProbe::configured(), 'empty host is not configured');

            putenv('REDIS_HOST=redis.invalid');
            self::assertTrue(SharedRedisPoolProbe::configured());
        } finally {
            // In a finally: a failed assertion above would otherwise leave the
            // modified environment for every test that runs after this one.
            if ($previous === false) {
                putenv('REDIS_HOST');
            } else {
                putenv('REDIS_HOST=' . $previous);
            }
        }
    }
}

/** Keeps the factory call out of the assertion line, so the finally reads clearly. */
final class SharedRedisPoolProbe
{
    public static function configured(): bool
    {
        return RedisSharedPool::fromEnvironment(4)->isConfigured();
    }
}
