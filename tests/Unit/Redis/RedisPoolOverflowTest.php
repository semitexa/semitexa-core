<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Redis\RedisUnavailableException;
use Swoole\Coroutine;

/**
 * A borrow that waits out the pool gets an extra client so a stall does not
 * deadlock the worker. Those extras used to have no bound: a pool of 2 held
 * for 3 s with 50 borrowers opened 50 more connections, and a Redis stall
 * under a thousand requests would open a thousand. No Redis is needed:
 * Predis connects on the first command, and nothing here sends one.
 */
final class RedisPoolOverflowTest extends TestCase
{
    private const BORROWERS = 50;

    #[Test]
    public function a_held_pool_opens_at_most_its_size_in_extra_clients_and_refuses_the_rest(): void
    {
        $pool = new RedisConnectionPool(2, ['host' => '127.0.0.1', 'port' => 6379]);
        $pooled = [];
        $extras = [];
        $refused = 0;
        $other = null;

        Coroutine\run(function () use ($pool, &$pooled, &$extras, &$refused, &$other): void {
            // Every pooled client is held, as by a stalled read.
            $pooled = [$pool->get(), $pool->get()];
            $wg = new Coroutine\WaitGroup();
            for ($i = 0; $i < self::BORROWERS; $i++) {
                $wg->add();
                Coroutine::create(function () use ($pool, $wg, &$extras, &$refused, &$other): void {
                    try {
                        $extras[] = $pool->get();
                    } catch (RedisUnavailableException) {
                        $refused++;
                    } catch (\Throwable $e) {
                        $other = $e;
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();
            // Everything comes back: the pool keeps 2, the extras are retired.
            foreach ([...$pooled, ...$extras] as $client) {
                $pool->put($client);
            }
        });

        self::assertNull($other);
        self::assertCount(2, $extras, 'at most the pool size in extra clients');
        self::assertSame(self::BORROWERS - 2, $refused, 'the rest fail like an outage, which callers degrade on');
        self::assertContainsOnlyInstancesOf(ClientInterface::class, $extras);
        self::assertSame(0, (new \ReflectionProperty(RedisConnectionPool::class, 'extra'))->getValue($pool), 'returned extras are retired');
    }
}
