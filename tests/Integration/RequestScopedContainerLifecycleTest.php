<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Application;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Lifecycle\PerRequestStateRegistry;
use Semitexa\Core\Lifecycle\TestStateResetRegistry;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tests\Fixtures\AuthDemo\AuthDemoStubAuthHandler;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\ProtectedPermissionPingPayload;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\ProtectedPingPayload;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\PublicPingPayload;
use Semitexa\Core\Tests\Fixtures\AuthDemo\Payload\ServicePingPayload;
use Semitexa\Core\Tests\Support\FixtureModules;

/**
 * Lifecycle test for the request-scoped container.
 *
 * The request-scoped container caches per-request bindings (Request, Session,
 * CookieJar, AuthContext, TenantContext, LocaleContext, plus anything else
 * a phase explicitly set()s into it). If those bindings survive a request,
 * the next request running on the same Application instance can observe
 * the previous request's data — directly via container->get(), and
 * indirectly through any service that resolved a binding mid-request.
 *
 * In Swoole HTTP mode each request gets a fresh `new Application()` (see
 * SwooleBootstrap), so the container is rebuilt. In CLI / queue / test mode
 * the same Application instance handles many requests in sequence — same
 * RequestScopedContainer instance — so any leak is observable. These tests
 * pin the contract that Application::handleRequest's finally block wipes
 * the per-request cache before returning.
 *
 * The routes and the stub auth handler are core's own fixtures
 * (tests/Fixtures/AuthDemo), so every response path here is really reached
 * in any app, not only in the monorepo.
 */
final class RequestScopedContainerLifecycleTest extends TestCase
{
    private Application $app;

    public static function setUpBeforeClass(): void
    {
        FixtureModules::install();
    }

    public static function tearDownAfterClass(): void
    {
        FixtureModules::uninstall();
    }

    protected function setUp(): void
    {
        $this->app = new Application();
        TestStateResetRegistry::resetAllForTesting();
        AuthContextStore::clear();
        AuthContextStore::clearFallback();
    }

    protected function tearDown(): void
    {
        TestStateResetRegistry::resetAllForTesting();
        AuthContextStore::clear();
        AuthContextStore::clearFallback();
        $this->app->requestScopedContainer->reset();
    }

    // ------------------------------------------------------------------
    //  Cache wipe across all response paths
    // ------------------------------------------------------------------

    #[Test]
    public function request_scoped_container_resets_after_2xx_request(): void
    {
        $response = $this->app->handleRequest($this->makeRequest(PublicPingPayload::PATH));

        self::assertSame(200, $response->getStatusCode(), 'precondition: public route returns 200');
        $this->assertRequestScopedCacheEmpty();
    }

    #[Test]
    public function request_scoped_container_resets_after_controlled_4xx_response(): void
    {
        // /__semitexa/error/404 is the framework's controlled 404 page.
        $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));

        $this->assertRequestScopedCacheEmpty();
    }

    #[Test]
    public function request_scoped_container_resets_after_controlled_5xx_response(): void
    {
        $response = $this->app->handleRequest($this->makeRequest('/__semitexa/error/500'));

        self::assertSame(500, $response->getStatusCode());
        $this->assertRequestScopedCacheEmpty();
    }

    #[Test]
    public function request_scoped_container_resets_after_401_authentication_failure(): void
    {
        // Protected route with NO auth → 401 from PreHydrationAuthGate.
        $response = $this->app->handleRequest($this->makeRequest(ProtectedPingPayload::PATH));

        self::assertSame(401, $response->getStatusCode(), 'precondition: protected route returns 401 for guest');
        $this->assertRequestScopedCacheEmpty();
    }

    #[Test]
    public function request_scoped_container_resets_after_403_authorization_failure(): void
    {
        // Authenticated user with no permission → 403 from AuthorizationListener.
        $response = $this->app->handleRequest($this->makeRequest(
            ProtectedPermissionPingPayload::PATH,
            [AuthDemoStubAuthHandler::HEADER => AuthDemoStubAuthHandler::USER_PREFIX . 'no-perms-user'],
        ));

        self::assertSame(403, $response->getStatusCode(), 'precondition: missing permission returns 403');
        $this->assertRequestScopedCacheEmpty();
    }

    // ------------------------------------------------------------------
    //  Cross-request leak prevention
    // ------------------------------------------------------------------

    #[Test]
    public function two_sequential_requests_do_not_share_request_scoped_request_instance(): void
    {
        // Request A — runs the SessionPhase which sets Request:: into the cache.
        $a = $this->makeRequest(PublicPingPayload::PATH);
        self::assertSame(200, $this->app->handleRequest($a)->getStatusCode(), 'precondition: request A ran');

        // After Application::handleRequest's reset, the cache is empty —
        // so peeking before the next request shows nothing.
        self::assertFalse(
            $this->app->requestScopedContainer->has(Request::class),
            'Request A leaked into the cache after handleRequest returned',
        );

        // Request B — get a different Request instance into the cache.
        $b = $this->makeRequest(PublicPingPayload::PATH);
        self::assertSame(200, $this->app->handleRequest($b)->getStatusCode(), 'precondition: request B ran');

        // Container is empty again — proving the framework owns the lifecycle.
        self::assertFalse($this->app->requestScopedContainer->has(Request::class));
    }

    #[Test]
    public function authenticated_user_does_not_leak_into_a_later_anonymous_request(): void
    {
        // Authenticated request as user A, on a route that only lets a user in.
        $first = $this->app->handleRequest($this->makeRequest(
            ProtectedPingPayload::PATH,
            [AuthDemoStubAuthHandler::HEADER => AuthDemoStubAuthHandler::USER_PREFIX . 'leak-test-user-A'],
        ));
        self::assertSame(200, $first->getStatusCode(), 'precondition: user A was authenticated');

        // After reset, the per-request CACHE entries (Request, Session,
        // CookieJar) are gone. AuthContextInterface falls through to the
        // root-container binding, so has() returns true regardless — the
        // *leakable* surface is the cache + the AuthContextStore, both
        // of which must be clean.
        self::assertFalse($this->app->requestScopedContainer->has(Request::class));
        self::assertNull(AuthContextStore::getUser(), 'AuthContextStore static fallback leaked user A');

        // Anonymous request — nobody set anything into the container; the
        // gate would reject the request, but the container starts clean.
        $response = $this->app->handleRequest($this->makeRequest(ProtectedPingPayload::PATH));

        self::assertSame(401, $response->getStatusCode(), 'second request must be rejected as guest, not authenticated as user A');
    }

    #[Test]
    public function service_principal_does_not_leak_into_a_later_protected_user_route(): void
    {
        // First: dispatch a service-token request to a service route.
        $first = $this->app->handleRequest($this->makeRequest(
            ServicePingPayload::PATH,
            [AuthDemoStubAuthHandler::HEADER => AuthDemoStubAuthHandler::SERVICE_PREFIX . 'leak-test-service-A'],
        ));
        self::assertSame(200, $first->getStatusCode(), 'precondition: the service principal was authenticated');

        // Then: a protected route with NO auth header. If the service
        // principal had leaked, PreHydrationAuthGate would reject as
        // "service authentication when user is required" or worse,
        // accept it incorrectly. The clean state means a plain 401 guest.
        $response = $this->app->handleRequest($this->makeRequest(ProtectedPingPayload::PATH));

        self::assertSame(401, $response->getStatusCode(), 'protected route must reject the second request as guest');
        $this->assertRequestScopedCacheEmpty();
    }

    // ------------------------------------------------------------------
    //  Coexistence with PerRequestStateRegistry
    // ------------------------------------------------------------------

    #[Test]
    public function per_request_registry_and_request_scoped_container_both_reset(): void
    {
        // Set state in both — request-scoped container via the request lifecycle,
        // PerRequestStateRegistry via a plain auth handler.
        $response = $this->app->handleRequest($this->makeRequest(
            ProtectedPingPayload::PATH,
            [AuthDemoStubAuthHandler::HEADER => AuthDemoStubAuthHandler::USER_PREFIX . 'coexist-test-user'],
        ));
        self::assertSame(200, $response->getStatusCode(), 'precondition: the user was authenticated');

        // PerRequestStateRegistry cleanup and request-scoped container reset
        // are independent — both must run after a single dispatch.
        self::assertNull(AuthContextStore::getUser(), 'PerRequestStateRegistry must clear AuthContextStore');
        $this->assertRequestScopedCacheEmpty();
    }

    #[Test]
    public function container_reset_runs_even_when_a_per_request_callback_throws(): void
    {
        // Register a throwing per-request callback that should NOT prevent the
        // request-scoped container reset from happening.
        PerRequestStateRegistry::register('lifecycle_test_throwing_callback', static function (): void {
            throw new \RuntimeException('intentional');
        });

        try {
            $this->app->handleRequest($this->makeRequest('/__semitexa/error/404'));
            $this->assertRequestScopedCacheEmpty();
        } finally {
            PerRequestStateRegistry::register('lifecycle_test_throwing_callback', static fn (): null => null);
        }
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function assertRequestScopedCacheEmpty(): void
    {
        $rsc = $this->app->requestScopedContainer;
        self::assertFalse($rsc->has(Request::class), 'Request leaked into requestScopedContainer');
        self::assertFalse($rsc->has(SessionInterface::class), 'Session leaked into requestScopedContainer');
        self::assertFalse($rsc->has(CookieJarInterface::class), 'CookieJar leaked into requestScopedContainer');
        self::assertFalse($rsc->isExecutionContextReady(), 'ExecutionContext flag must be cleared after reset');

        // AuthContext / TenantContext / LocaleContext might be backed by a
        // root-container singleton (TenantContextStore::shared() etc.) —
        // they're allowed to remain has()=true because the framework
        // wrapper falls through to the root container. The test focuses
        // on the request-scoped *cache*, which is the leakable surface.
    }

    private function makeRequest(string $path, array $extraHeaders = []): Request
    {
        $headers = ['Accept' => 'application/json'] + $extraHeaders;
        return new Request(
            method: 'GET',
            uri: $path,
            headers: $headers,
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path],
            cookies: [],
            content: null,
        );
    }
}
