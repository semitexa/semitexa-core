<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Http\ClientIp;
use Semitexa\Core\RateLimit\InMemoryRateLimitStore;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\RateLimit\RateLimitKey;
use Semitexa\Core\RateLimit\RateLimitStorage;
use Semitexa\Core\RateLimit\RateLimitStoreInterface;
use Semitexa\Core\RateLimit\SwooleTableRateLimitStore;
use Semitexa\Core\Request;

final class RateLimiterTest extends TestCase
{
    private string|false $trustedProxies;

    protected function setUp(): void
    {
        $this->trustedProxies = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES');
    }

    protected function tearDown(): void
    {
        $this->trustedProxies === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES=' . $this->trustedProxies);
    }

    #[Test]
    public function the_limit_passes_and_the_next_one_waits(): void
    {
        $limiter = self::limiter(new InMemoryRateLimitStore(), '203.0.113.7');

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($limiter->attempt('ping', 3, 60, RateLimitKey::Ip, 1000.0)->allowed);
        }
        $refused = $limiter->attempt('ping', 3, 60, RateLimitKey::Ip, 1000.0);

        self::assertFalse($refused->allowed);
        self::assertSame(0, $refused->remaining);
        self::assertGreaterThan(0, $refused->retryAfter);
    }

    #[Test]
    public function a_burst_at_a_window_edge_cannot_pass_twice_the_limit(): void
    {
        $limiter = self::limiter(new InMemoryRateLimitStore(static fn (): int => 0), '203.0.113.7');
        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($limiter->attempt('edge', 10, 60, RateLimitKey::Ip, 59.0)->allowed);
        }

        // A fixed window would open a fresh ten at 60 s. Sliding, the ten just
        // spent still weigh 59/60 one second into the next slot.
        $refused = $limiter->attempt('edge', 10, 60, RateLimitKey::Ip, 61.0);
        self::assertFalse($refused->allowed);

        // 10 * (1 - 6/60) + 1 = 10: allowed exactly when Retry-After says so.
        self::assertSame(5, $refused->retryAfter);
        self::assertFalse($limiter->attempt('edge', 10, 60, RateLimitKey::Ip, 61.0 + $refused->retryAfter - 1)->allowed);
        self::assertTrue($limiter->attempt('edge', 10, 60, RateLimitKey::Ip, 61.0 + $refused->retryAfter)->allowed);
    }

    #[Test]
    public function a_refused_request_does_not_count(): void
    {
        $limiter = self::limiter(new InMemoryRateLimitStore(static fn (): int => 0), '203.0.113.7');
        $limiter->attempt('hammer', 2, 60, RateLimitKey::Ip, 0.0);
        $limiter->attempt('hammer', 2, 60, RateLimitKey::Ip, 0.0);
        for ($i = 0; $i < 50; $i++) {
            self::assertFalse($limiter->attempt('hammer', 2, 60, RateLimitKey::Ip, 1.0)->allowed);
        }

        // Slot 1, 30 s in, where slot 0 still weighs half: the two allowed
        // requests make 2 * 0.5 + 1 = 2, which fits. Counted, the fifty
        // refusals would make 52 * 0.5 + 1. (A probe at 120 s, two slots on,
        // passed either way: slot 0 no longer weighed.)
        self::assertTrue($limiter->attempt('hammer', 2, 60, RateLimitKey::Ip, 90.0)->allowed);
    }

    #[Test]
    public function addresses_users_and_buckets_count_apart(): void
    {
        $store = new InMemoryRateLimitStore();
        $one = self::limiter($store, '203.0.113.7');
        $two = self::limiter($store, '203.0.113.8');

        self::assertTrue($one->attempt('a', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
        self::assertFalse($one->attempt('a', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
        self::assertTrue($two->attempt('a', 1, 60, RateLimitKey::Ip, 10.0)->allowed, 'another address');
        self::assertTrue($one->attempt('b', 1, 60, RateLimitKey::Ip, 10.0)->allowed, 'another bucket');

        // One user on two addresses is one user; a guest by User counts by address.
        $alice = self::limiter($store, '198.51.100.1', 'alice');
        $aliceElsewhere = self::limiter($store, '198.51.100.2', 'alice');
        self::assertTrue($alice->attempt('u', 1, 60, RateLimitKey::User, 10.0)->allowed);
        self::assertFalse($aliceElsewhere->attempt('u', 1, 60, RateLimitKey::User, 10.0)->allowed);
        $guest = self::limiter($store, '203.0.113.9');
        self::assertTrue($guest->attempt('g', 1, 60, RateLimitKey::User, 10.0)->allowed);
        self::assertFalse(self::limiter($store, '203.0.113.9')->attempt('g', 1, 60, RateLimitKey::User, 10.0)->allowed, 'a guest is counted by address');
        self::assertTrue(self::limiter($store, '203.0.113.10')->attempt('g', 1, 60, RateLimitKey::User, 10.0)->allowed);

        // A subject of the caller's own.
        self::assertTrue($one->attempt('acct', 1, 60, 'account-42', 10.0)->allowed);
        self::assertFalse($two->attempt('acct', 1, 60, 'account-42', 10.0)->allowed);
    }

    #[Test]
    public function an_ipv6_client_counts_with_its_whole_slash_64(): void
    {
        $store = new InMemoryRateLimitStore();
        self::assertTrue(self::limiter($store, '2001:db8:1:2::1')->attempt('v6', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
        self::assertFalse(self::limiter($store, '2001:db8:1:2:ffff::9')->attempt('v6', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
        self::assertTrue(self::limiter($store, '2001:db8:1:3::1')->attempt('v6', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
    }

    #[Test]
    public function ipv4_clients_on_a_dual_stack_socket_count_apart(): void
    {
        // ::ffff:a.b.c.d has 64 zero bits in front for every IPv4 client: as a
        // /64 they all shared one counter (review, rl-1).
        $store = new InMemoryRateLimitStore();
        self::assertSame('1.2.3.4', RateLimiter::addressBucket('::ffff:1.2.3.4'));
        self::assertTrue(self::limiter($store, '::ffff:1.2.3.4')->attempt('v4', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
        self::assertTrue(self::limiter($store, '::ffff:9.9.9.9')->attempt('v4', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
        self::assertFalse(self::limiter($store, '1.2.3.4')->attempt('v4', 1, 60, RateLimitKey::Ip, 10.0)->allowed, 'mapped or not, one client');
    }

    #[Test]
    public function a_guest_under_a_user_limit_does_not_count_twice_into_the_address_limit(): void
    {
        $limiter = self::limiter(new InMemoryRateLimitStore(), '203.0.113.7');

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($limiter->attempt('both', 100, 60, RateLimitKey::User, 10.0)->allowed);
            self::assertTrue($limiter->attempt('both', 3, 60, RateLimitKey::Ip, 10.0)->allowed, "request {$i} fits the address limit of 3");
        }
    }

    #[Test]
    public function a_released_request_no_longer_counts(): void
    {
        $limiter = self::limiter(new InMemoryRateLimitStore(), '203.0.113.7');

        $limiter->release($limiter->attempt('r', 1, 60, RateLimitKey::Ip, 10.0));

        self::assertTrue($limiter->attempt('r', 1, 60, RateLimitKey::Ip, 10.0)->allowed);
    }

    #[Test]
    public function a_store_that_cannot_count_refuses_nobody(): void
    {
        $broken = new class () implements RateLimitStoreInterface {
            public function hit(string $currentKey, string $previousKey, int $ttlSeconds): ?array { return null; }
            public function undo(string $currentKey): void {}
        };

        self::assertTrue(self::limiter($broken, '203.0.113.7')->attempt('x', 1, 60, RateLimitKey::Ip, 1.0)->allowed);
    }

    #[Test]
    public function enforce_throws_the_429_with_its_retry_after(): void
    {
        $limiter = self::limiter(new InMemoryRateLimitStore(), '203.0.113.7');
        $limiter->enforce('e', 1, 60);

        try {
            $limiter->enforce('e', 1, 60);
            self::fail('the second request passed');
        } catch (RateLimitException $e) {
            self::assertSame(429, $e->getStatusCode()->value);
            self::assertGreaterThan(0, $e->getRetryAfter());
        }
    }

    #[Test]
    public function the_table_store_counts_across_instances_and_sweeps_old_windows(): void
    {
        $store = SwooleTableRateLimitStore::create(1024);

        self::assertSame([1, 0], $store->hit('60:5:k', '60:4:k', 120));
        self::assertSame([2, 0], $store->hit('60:5:k', '60:4:k', 120));
        self::assertSame([1, 2], $store->hit('60:6:k', '60:5:k', 120));
        $store->undo('60:6:k');
        self::assertSame([1, 2], $store->hit('60:6:k', '60:5:k', 120));

        // At 7 min the current slot is 7: slot 6 is the previous, 5 is gone.
        self::assertSame(1, $store->sweep(7 * 60));
        self::assertSame(1, $store->count());
    }

    #[Test]
    public function a_full_table_counts_nothing_rather_than_refusing(): void
    {
        $store = SwooleTableRateLimitStore::create(1);
        $last = [];
        for ($i = 0; $i < 4096 && $last !== null; $i++) {
            $last = $store->hit("60:1:k{$i}", "60:0:k{$i}", 120);
        }

        self::assertNull($last, 'a one-row table filled up');
    }

    #[Test]
    public function the_client_is_the_peer_unless_a_trusted_proxy_speaks_for_it(): void
    {
        $spoofed = ['X-Forwarded-For' => '6.6.6.6'];
        self::assertSame('203.0.113.7', ClientIp::of(self::request('203.0.113.7', $spoofed)), 'an untrusted peer cannot name another client');

        // Loopback is a trusted proxy: the rightmost untrusted hop is the client,
        // whatever the client prepended.
        self::assertSame('198.51.100.4', ClientIp::of(self::request('127.0.0.1', ['X-Forwarded-For' => '6.6.6.6, 198.51.100.4'])));

        putenv('TRUSTED_PROXIES=10.0.0.0/8');
        self::assertSame('198.51.100.4', ClientIp::of(self::request('10.0.0.2', ['X-Forwarded-For' => '6.6.6.6, 198.51.100.4, 10.0.0.9'])));
        self::assertSame('10.0.0.9', ClientIp::of(self::request('10.0.0.2', ['X-Forwarded-For' => 'garbage, 10.0.0.9'])), 'a hop that is no address stops the walk');
        self::assertSame('10.0.0.2', ClientIp::of(self::request('10.0.0.2', [])));
        self::assertSame('198.51.100.4', ClientIp::of(self::request('10.0.0.2', ['X-Forwarded-For' => '198.51.100.4:5678'])), 'a hop with a port');
        self::assertSame('2001:db8::1', ClientIp::of(self::request('10.0.0.2', ['X-Forwarded-For' => '[2001:db8::1]:443'])), 'a bracketed IPv6 hop');
    }

    private static function limiter(RateLimitStoreInterface $store, string $address, ?string $userId = null): RateLimiter
    {
        $storage = new RateLimitStorage();
        $storage->use($store);
        $limiter = new RateLimiter();
        self::set($limiter, 'storage', $storage);
        self::set($limiter, 'request', self::request($address, []));
        self::set($limiter, 'authContext', self::auth($userId));

        return $limiter;
    }

    /** @param array<string, string> $headers */
    private static function request(string $address, array $headers): Request
    {
        return new Request('GET', '/', $headers, [], [], ['remote_addr' => $address], []);
    }

    private static function auth(?string $userId): AuthContextInterface
    {
        $user = $userId === null ? null : new class ($userId) implements AuthenticatableInterface {
            public function __construct(private string $id) {}
            public function getId(): string { return $this->id; }
            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return $this->id; }
        };

        return new class ($user) implements AuthContextInterface {
            public function __construct(private ?AuthenticatableInterface $user) {}
            public function getUser(): ?AuthenticatableInterface { return $this->user; }
            public function isGuest(): bool { return $this->user === null; }
            public function setUser(?AuthenticatableInterface $user): void { $this->user = $user; }
            public static function get(): ?self { return null; }
            public static function getOrFail(): self { throw new \LogicException('not in tests'); }
        };
    }

    private static function set(object $target, string $property, mixed $value): void
    {
        (new \ReflectionProperty($target, $property))->setValue($target, $value);
    }
}
