<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SessionStoreFullException;
use Semitexa\Core\Tenant\DefaultTenantContextStore;

/**
 * tk-swoole-session-table-exhaustion-dos: a session the table had no room for
 * was dropped in silence, so a sign-in answered "signed in" and the next
 * request was a guest again. The sign-in is now told to retry, an anonymous
 * page is still served, and the full table is logged once, not per request.
 */
final class ASessionThatCannotBeStoredSaysSoTest extends TestCase
{
    private string|false $redisHostBefore = false;

    /** @var list<string> */
    private array $errors = [];

    /** What the worker-wide "last logged" stamp held before the test; put back after it. */
    private int $storeFullLoggedAtBefore = 0;

    protected function setUp(): void
    {
        $this->redisHostBefore = getenv('REDIS_HOST');
        putenv('REDIS_HOST=');
        $stamp = $this->lastLogStamp()->getValue();
        self::assertIsInt($stamp);
        $this->storeFullLoggedAtBefore = $stamp;
        $this->lastLogStamp()->setValue(null, 0);
    }

    /** PHPUnit runs this after a failed assertion too. */
    protected function tearDown(): void
    {
        $this->redisHostBefore === false ? putenv('REDIS_HOST') : putenv('REDIS_HOST=' . $this->redisHostBefore);
        $this->lastLogStamp()->setValue(null, $this->storeFullLoggedAtBefore);
    }

    #[Test]
    public function a_sign_in_whose_session_found_no_room_is_told_to_retry(): void
    {
        [$phase, $scope] = $this->phase($this->fullStore());
        $context = new RequestLifecycleContext($this->request('POST', '/login'));
        $phase->execute($context);
        $session = $scope->get(SessionInterface::class);
        self::assertInstanceOf(Session::class, $session);
        $session->regenerate();
        $session->set('user_id', 7);
        $this->setALocaleCookie($scope);

        $response = $phase->finalize($context, HttpResponse::redirect('/dashboard'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaders()['Retry-After'] ?? null);
        self::assertSame('The session could not be stored, try again in a moment.', $response->getContent());
        self::assertArrayNotHasKey('Location', $response->getHeaders());
        $cookies = implode("\n", (array) ($response->getHeaders()['Set-Cookie'] ?? []));
        self::assertStringContainsString('locale=uk', $cookies, 'cookies the request set itself still go out');
        self::assertStringNotContainsString('semitexa_session=', $cookies, 'no cookie for a session that is not stored');
    }

    #[Test]
    public function an_anonymous_page_is_still_served(): void
    {
        [$phase, $scope] = $this->phase($this->fullStore());
        $context = new RequestLifecycleContext($this->request('GET', '/'));
        $phase->execute($context);
        $this->setALocaleCookie($scope);

        $response = $phase->finalize($context, new HttpResponse('the page'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('the page', $response->getContent());
        $cookies = implode("\n", (array) ($response->getHeaders()['Set-Cookie'] ?? []));
        self::assertStringContainsString('locale=uk', $cookies);
        self::assertStringNotContainsString('semitexa_session=', $cookies);
    }

    #[Test]
    public function a_full_store_is_logged_once_and_written_once_per_request(): void
    {
        $writes = new \ArrayObject();
        [$phase] = $this->phase($this->fullStore($writes));

        for ($i = 0; $i < 5; $i++) {
            $context = new RequestLifecycleContext($this->request('GET', '/'));
            $phase->execute($context);
            $phase->finalize($context, new HttpResponse('the page'));
        }

        self::assertSame(['Session persistence failed'], $this->errors);
        self::assertCount(5, $writes, 'a full table is not written a second time in the same request');
    }

    /** @param \ArrayObject<int, string>|null $writes */
    private function fullStore(?\ArrayObject $writes = null): SessionHandlerInterface
    {
        return new class ($writes ?? new \ArrayObject()) implements SessionHandlerInterface {
            /** @param \ArrayObject<int, string> $writes */
            public function __construct(private readonly \ArrayObject $writes)
            {
            }

            public function read(string $sessionId): array
            {
                return [];
            }

            public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
            {
                $this->writes->append($sessionId);
                throw new SessionStoreFullException('The session table is full.');
            }

            public function destroy(string $sessionId): void
            {
            }
        };
    }

    /** @return array{SessionPhase, RequestScopedContainer} */
    private function phase(SessionHandlerInterface $store): array
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message): void {
            $this->errors[] = $message;
        });
        $container = new class ($logger) implements ContainerInterface {
            public function __construct(private readonly LoggerInterface $logger)
            {
            }

            public function get(string $id): mixed
            {
                return $id === LoggerInterface::class ? $this->logger : throw new \RuntimeException("not bound: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === LoggerInterface::class;
            }
        };
        $scope = new RequestScopedContainer($container);
        $phase = new SessionPhase($container, $scope, new DefaultTenantContextStore(), null);
        (new \ReflectionProperty(SessionPhase::class, 'sessionHandler'))->setValue($phase, $store);

        return [$phase, $scope];
    }

    private function setALocaleCookie(RequestScopedContainer $scope): void
    {
        $jar = $scope->get(CookieJarInterface::class);
        self::assertInstanceOf(CookieJarInterface::class, $jar);
        $jar->set('locale', 'uk', ['path' => '/']);
    }

    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], [], ['REQUEST_URI' => $path], []);
    }

    private function lastLogStamp(): \ReflectionProperty
    {
        return new \ReflectionProperty(SessionPhase::class, 'storeFullLoggedAt');
    }
}
