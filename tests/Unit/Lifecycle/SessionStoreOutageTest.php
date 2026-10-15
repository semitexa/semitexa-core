<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\DefaultTenantContextStore;

/**
 * A session store that cannot be read must not take the page down.
 *
 * Measured 2026-10-15: with Redis stopped, every page of the dev site answered
 * 500, anonymous visitors included, because SessionPhase read the session
 * before anything else and let the connection error escape.
 */
final class SessionStoreOutageTest extends TestCase
{
    private const SID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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

    #[Test]
    public function an_unreadable_store_serves_the_request_on_a_transient_session(): void
    {
        $store = new OutageSessionHandler(readable: false);
        [$phase, $scope] = $this->phase($store);
        $context = new RequestLifecycleContext($this->request());

        $phase->execute($context);

        $session = $scope->get(SessionInterface::class);
        self::assertInstanceOf(Session::class, $session);
        self::assertFalse($session->persists());
        self::assertSame(self::SID, $session->getSessionIdForCookie(), 'the visitor keeps their session id');
        self::assertInstanceOf(AuthContextInterface::class, $scope->get(AuthContextInterface::class));

        $session->set('written_during_outage', true);
        $response = $phase->finalize($context, new HttpResponse('ok'));

        self::assertSame([], $store->writes, 'nothing may be written back over the real session');
        self::assertArrayNotHasKey('Set-Cookie', $response->getHeaders(), 'cookies are left as they were');
    }

    #[Test]
    public function a_readable_store_still_persists_and_sets_cookies(): void
    {
        $store = new OutageSessionHandler(readable: true);
        [$phase, $scope] = $this->phase($store);
        $context = new RequestLifecycleContext($this->request());

        $phase->execute($context);
        $session = $scope->get(SessionInterface::class);
        self::assertInstanceOf(Session::class, $session);
        self::assertTrue($session->persists());

        $response = $phase->finalize($context, new HttpResponse('ok'));

        self::assertSame([self::SID], $store->writes);
        self::assertArrayHasKey('Set-Cookie', $response->getHeaders());
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

    private function request(): Request
    {
        return new Request('GET', '/', [], [], [], ['REQUEST_URI' => '/'], ['semitexa_session' => self::SID]);
    }
}

final class OutageSessionHandler implements SessionHandlerInterface
{
    /** @var list<string> */
    public array $writes = [];

    public function __construct(private readonly bool $readable)
    {
    }

    public function read(string $sessionId): array
    {
        if (!$this->readable) {
            throw new \RuntimeException('php_network_getaddresses: getaddrinfo for redis failed');
        }

        return [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
    {
        $this->writes[] = $sessionId;
    }

    public function destroy(string $sessionId): void
    {
    }
}
