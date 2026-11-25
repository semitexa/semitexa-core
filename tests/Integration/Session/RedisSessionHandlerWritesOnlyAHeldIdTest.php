<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Session;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Tests\Support\RequiresRedis;
use Swoole\Coroutine;

/**
 * RedisSessionHandler::writeIfHeld() on a real Redis: the script that lets
 * Session::save() write an id only while it is still a session
 * (tk-session-inflight-request-rewrites-a-retired-session), and the marker a
 * sign-in leaves there, with the lifetime Redis gives it.
 */
final class RedisSessionHandlerWritesOnlyAHeldIdTest extends TestCase
{
    use RequiresRedis;

    private const PREFIX = 'semitexa_session:';
    private const MARKER = ['__retired__' => 1];

    /** @var list<string> */
    private array $ids = [];

    private ?Client $redis = null;

    protected function tearDown(): void
    {
        if ($this->redis !== null && $this->ids !== []) {
            $this->redis->del(array_map(static fn (string $id): string => self::PREFIX . $id, $this->ids));
        }
    }

    #[Test]
    public function a_held_id_is_written_with_its_lifetime(): void
    {
        $id = $this->id();
        $this->redis()->setex(self::PREFIX . $id, 50, '{"user_id":7,"__flash__":[]}');

        $wrote = $this->onHandler(static fn (RedisSessionHandler $h): bool => $h->writeIfHeld($id, ['user_id' => 7, 'cart' => 3, '__flash__' => []], 1800, self::MARKER));

        self::assertTrue($wrote);
        self::assertSame('{"user_id":7,"cart":3,"__flash__":[]}', $this->redis()->get(self::PREFIX . $id));
        $ttl = $this->redis()->ttl(self::PREFIX . $id);
        self::assertGreaterThan(1700, $ttl);
        self::assertLessThanOrEqual(1800, $ttl);
    }

    #[Test]
    public function a_marked_id_is_left_as_it_is(): void
    {
        $id = $this->id();
        $this->onHandler(static fn (RedisSessionHandler $h) => $h->write($id, self::MARKER, 10));
        self::assertSame('{"__retired__":1}', $this->redis()->get(self::PREFIX . $id), 'what a sign-in leaves under the old id');

        $wrote = $this->onHandler(static fn (RedisSessionHandler $h): bool => $h->writeIfHeld($id, ['user_id' => 7, '__flash__' => []], 1800, self::MARKER));

        self::assertFalse($wrote);
        self::assertSame('{"__retired__":1}', $this->redis()->get(self::PREFIX . $id));
        self::assertLessThanOrEqual(10, $this->redis()->ttl(self::PREFIX . $id), 'and it still runs out in its own time');
    }

    #[Test]
    public function an_id_redis_does_not_hold_is_not_created(): void
    {
        $id = $this->id();

        $wrote = $this->onHandler(static fn (RedisSessionHandler $h): bool => $h->writeIfHeld($id, ['user_id' => 7, '__flash__' => []], 1800, self::MARKER));

        self::assertFalse($wrote);
        self::assertSame(0, $this->redis()->exists(self::PREFIX . $id));
    }

    /** The whole of it, through Session: a request in flight across a sign-out. */
    #[Test]
    public function a_request_in_flight_across_a_sign_out_does_not_bring_the_session_back(): void
    {
        $id = $this->id();
        $this->redis()->setex(self::PREFIX . $id, 600, '{"user_id":7,"__flash__":[]}');

        $guestId = $this->onHandler(function (RedisSessionHandler $h) use ($id): string {
            $poll = Session::open($id, $h, 'semitexa_session');
            $out = Session::open($id, $h, 'semitexa_session');
            $out->clear();
            $out->regenerate();
            $out->save();
            $this->ids[] = $out->getSessionIdForCookie();

            self::assertSame(7, $poll->get('user_id'));
            $poll->save();
            self::assertTrue($poll->isOnRetiredId());
            self::assertNull(Session::open($id, $h, 'semitexa_session')->get('user_id'));

            return $out->getSessionIdForCookie();
        });

        self::assertSame('{"__retired__":1}', $this->redis()->get(self::PREFIX . $id));
        $ttl = $this->redis()->ttl(self::PREFIX . $id);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(Session::RETIRED_ID_GRACE_SECONDS, $ttl);
        $guest = $this->redis()->get(self::PREFIX . $guestId);
        self::assertIsString($guest);
        self::assertStringContainsString('"__flash__":[]', $guest, 'the guest session the sign-out left');
        self::assertStringNotContainsString('user_id', $guest);
    }

    /**
     * @template T
     * @param \Closure(RedisSessionHandler): T $work
     * @return T
     */
    private function onHandler(\Closure $work): mixed
    {
        $config = self::redisConfig(self::reachableRedisHost());
        $results = [];
        $failure = null;
        Coroutine\run(static function () use ($config, $work, &$results, &$failure): void {
            try {
                $results[] = $work(new RedisSessionHandler(new RedisConnectionPool(1, $config)));
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
        self::assertCount(1, $results, 'the work ran');

        return $results[0];
    }

    private function id(): string
    {
        return $this->ids[] = Session::generateId();
    }

    private function redis(): Client
    {
        return $this->redis ??= new Client(self::redisConfig(self::reachableRedisHost()) + ['read_write_timeout' => 5]);
    }
}
