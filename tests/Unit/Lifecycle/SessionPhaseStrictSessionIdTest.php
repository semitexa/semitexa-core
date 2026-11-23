<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Tenant\DefaultTenantContextStore;

/**
 * The client does not choose the id its session lives under
 * (tk-session-phase-missing-strict-mode-fixation, CWE-384).
 *
 * Until this, any 32-byte cookie value was adopted: stored under, given a CSRF
 * token and sent back in Set-Cookie, `attacker:fixed:id:0123456789abcd`
 * included. An id is now kept only when it has the shape the server mints and
 * the store already holds it. Runs on the real table handler, so "the check
 * creates no row" is measured on the store that has a capacity.
 */
final class SessionPhaseStrictSessionIdTest extends TestCase
{
    private const WELL_FORMED = '0123456789abcdef0123456789abcdef';

    private string|false $redisHostBefore = false;

    protected function setUp(): void
    {
        $this->redisHostBefore = getenv('REDIS_HOST');
        putenv('REDIS_HOST=');
    }

    protected function tearDown(): void
    {
        $this->redisHostBefore === false ? putenv('REDIS_HOST') : putenv('REDIS_HOST=' . $this->redisHostBefore);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedIds(): iterable
    {
        yield 'the filed attack: 32 bytes, not hex' => ['attacker:fixed:id:0123456789abcd'];
        yield 'uppercase hex' => ['0123456789ABCDEF0123456789ABCDEF'];
        yield 'one uppercase character' => ['0123456789abcdef0123456789abcdeF'];
        yield 'too short' => ['0123456789abcdef0123456789abcde'];
        yield 'too long' => ['0123456789abcdef0123456789abcdef0'];
        yield 'a trailing newline' => [self::WELL_FORMED . "\n"];
        yield '32 bytes with a space' => ['0123456789abcdef 123456789abcdef'];
    }

    #[Test]
    #[DataProvider('malformedIds')]
    public function a_malformed_cookie_gets_a_fresh_id(string $presented): void
    {
        $table = $this->table();
        // Stored under the malformed id on purpose: even then it is not used.
        $table->set($presented, ['data' => '{"planted":true,"__flash__":[]}', 'expires_at' => time() + 600]);
        [$phase, $scope] = $this->phase(new SwooleTableSessionHandler($table));
        $context = new RequestLifecycleContext($this->request($presented));

        $phase->execute($context);
        $session = $this->session($scope);

        self::assertNotSame($presented, $session->getId());
        self::assertTrue(Session::isWellFormedId($session->getId()));
        self::assertNull($session->get('planted'), 'nothing is read from under a malformed id');

        $response = $phase->finalize($context, new HttpResponse('ok'));

        self::assertSame($session->getId(), $this->sessionCookie($response));
        self::assertTrue($table->exists($session->getId()));
    }

    #[Test]
    public function an_unknown_well_formed_id_is_not_adopted(): void
    {
        $table = $this->table();
        [$phase, $scope] = $this->phase(new SwooleTableSessionHandler($table));
        $context = new RequestLifecycleContext($this->request(self::WELL_FORMED));

        $phase->execute($context);
        $session = $this->session($scope);

        self::assertNotSame(self::WELL_FORMED, $session->getId());
        self::assertTrue(Session::isWellFormedId($session->getId()));
        self::assertSame(0, $table->count(), 'asking whether the store holds an id creates no row');

        $response = $phase->finalize($context, new HttpResponse('ok'));

        self::assertSame($session->getId(), $this->sessionCookie($response), 'the cookie carries the id the server made');
        self::assertFalse($table->exists(self::WELL_FORMED), 'nothing is stored under the id the client chose');
        self::assertSame(1, $table->count());
    }

    #[Test]
    public function the_fresh_id_is_the_one_the_next_request_is_recognised_by(): void
    {
        $table = $this->table();
        $handler = new SwooleTableSessionHandler($table);

        [$phase, $scope] = $this->phase($handler);
        $first = new RequestLifecycleContext($this->request(self::WELL_FORMED));
        $phase->execute($first);
        $this->session($scope)->set('cart', 3);
        $issued = $this->sessionCookie($phase->finalize($first, new HttpResponse('ok')));

        [$phase, $scope] = $this->phase($handler);
        $phase->execute(new RequestLifecycleContext($this->request($issued)));

        self::assertSame($issued, $this->session($scope)->getId());
        self::assertSame(3, $this->session($scope)->get('cart'));
    }

    #[Test]
    public function a_known_id_is_kept(): void
    {
        $table = $this->table();
        $handler = new SwooleTableSessionHandler($table);
        $handler->write(self::WELL_FORMED, ['user_id' => 7, '__flash__' => []], 600);
        [$phase, $scope] = $this->phase($handler);
        $context = new RequestLifecycleContext($this->request(self::WELL_FORMED));

        $phase->execute($context);
        $session = $this->session($scope);

        self::assertSame(self::WELL_FORMED, $session->getId());
        self::assertSame(7, $session->get('user_id'));

        $response = $phase->finalize($context, new HttpResponse('ok'));

        self::assertSame(self::WELL_FORMED, $this->sessionCookie($response));
        self::assertSame(1, $table->count());
    }

    #[Test]
    public function an_expired_id_is_not_revived(): void
    {
        $table = $this->table();
        $table->set(self::WELL_FORMED, ['data' => '{"user_id":7,"__flash__":[]}', 'expires_at' => time() - 10]);
        [$phase, $scope] = $this->phase(new SwooleTableSessionHandler($table));

        $phase->execute(new RequestLifecycleContext($this->request(self::WELL_FORMED)));

        $id = $this->session($scope)->getId();
        self::assertTrue(Session::isWellFormedId($id));
        self::assertNotSame(self::WELL_FORMED, $id);
        self::assertNull($this->session($scope)->get('user_id'));
    }

    #[Test]
    public function during_a_store_outage_a_malformed_id_is_not_kept_either(): void
    {
        $unreadable = new class implements SessionHandlerInterface {
            public function read(string $sessionId): array
            {
                throw new \RuntimeException('store down');
            }

            public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
            {
            }

            public function destroy(string $sessionId): void
            {
            }
        };
        [$phase, $scope] = $this->phase($unreadable);

        $phase->execute(new RequestLifecycleContext($this->request('attacker:fixed:id:0123456789abcd')));

        self::assertFalse($this->session($scope)->persists());
        self::assertTrue(Session::isWellFormedId($this->session($scope)->getId()));
    }

    private function table(): \Swoole\Table
    {
        $table = new \Swoole\Table(64);
        $table->column('data', \Swoole\Table::TYPE_STRING, 1024);
        $table->column('expires_at', \Swoole\Table::TYPE_INT);
        $table->create();

        return $table;
    }

    private function session(RequestScopedContainer $scope): Session
    {
        $session = $scope->get(SessionInterface::class);
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }

    /** The value of the session cookie the response sets, '' when it sets none. */
    private function sessionCookie(HttpResponse $response): string
    {
        foreach ((array) ($response->getHeaders()['Set-Cookie'] ?? []) as $line) {
            if (preg_match('/^semitexa_session=([^;]*)/', $line, $m) === 1) {
                return rawurldecode($m[1]);
            }
        }

        return '';
    }

    /** @return array{SessionPhase, RequestScopedContainer} */
    private function phase(SessionHandlerInterface $store): array
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $scope = new RequestScopedContainer($container);
        $phase = new SessionPhase($container, $scope, new DefaultTenantContextStore(), null);
        (new \ReflectionProperty(SessionPhase::class, 'sessionHandler'))->setValue($phase, $store);

        return [$phase, $scope];
    }

    private function request(string $sessionCookie): Request
    {
        return new Request('GET', '/', [], [], [], ['REQUEST_URI' => '/'], ['semitexa_session' => $sessionCookie]);
    }
}
