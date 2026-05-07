<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver;
use Semitexa\Core\Application;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Request;
use Semitexa\Modules\AuthDemo\Application\Payload\Request\ProtectedPermissionPingPayload;
use Semitexa\Modules\AuthDemo\Application\Service\AuthDemoCapabilityStore;
use Semitexa\Modules\AuthDemo\Application\Service\AuthDemoPermissionStore;
use Semitexa\Modules\AuthDemo\Application\Service\AuthDemoStubAuthHandler;
use Semitexa\Modules\AuthDemo\Domain\Model\RuntimeCapability;
use Semitexa\Modules\WebhookDemo\Application\Payload\Request\SignedCapabilityWebhookPayload;
use Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoEventStore;
use Semitexa\Modules\WebhookDemo\Application\Service\WebhookDemoServiceCapabilityStore;
use Semitexa\Modules\WebhookDemo\Domain\Model\WebhookDemoServiceCapability;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * Authenticated companion to AllRoutesRuntimeSmokeTest.
 *
 * The anonymous smoke proves every route enters the runtime safely as a
 * guest (no 404/500/fatal). This test does the same with the CORRECT auth
 * domain per route — User token for #[AsProtectedPayload], Service token
 * for non-webhook #[AsServicePayload], and HMAC signatures for
 * #[AsWebhookReceiver]. It also pins the cross-domain rejection contracts:
 * a User token must never satisfy a Service route, a generic Service token
 * must never bypass webhook signature verification, and so on.
 *
 * Pass criteria per protected/service route under the correct auth domain:
 *   2xx, 3xx                — handler ran with authenticated principal
 *   4xx (other than 401)    — controlled validation/permission/capability response
 *   401                     — UNEXPECTED (auth domain matched the route)
 *   404                     — fail unless RouteRegistry::find still matches the path
 *   500                     — fail unless the path is in NEEDS_FIXTURES
 * Boundary tests are dedicated; not part of the per-route loop.
 */
final class AuthenticatedRoutesRuntimeSmokeTest extends TestCase
{
    private const SECRET = 'auth-smoke-test-secret';
    private const SMOKE_USER = 'smoke-user';
    private const SMOKE_SERVICE = 'smoke-service';

    /**
     * Routes intentionally not driven through Application::handleRequest in
     * a single-shot smoke test. Long-lived streaming endpoints that would
     * block the smoke loop.
     *
     * @var array<string,string>
     */
    private const SKIP = [
        '/sse'             => 'long-lived SSE stream; blocks the smoke loop. Covered by SsrPolygon deferred-render tests.',
        '/__semitexa_kiss' => 'long-lived SSE keep-alive stream; would block.',
        '/__semitexa_hug'  => 'SSR fallback streamed via TransportType::Sse; would block.',
    ];

    /**
     * Routes that require domain fixtures (DB seeds, tenant context, etc.) to
     * produce a happy 200 but should still go through the runtime cleanly.
     * Same set as the anonymous smoke — adding auth doesn't change which
     * routes need a customer record on disk.
     *
     * @var array<string,string>
     */
    private const NEEDS_FIXTURES = [
        '/playground/customers'                   => 'lists customers from ORM seed',
        '/playground/orm'                         => 'ORM landing reads platform-user db',
        '/playground/orm/articles/{id}'           => 'ORM article lookup needs seeded id',
        '/ssr-polygon/deferred/auth-aware'        => 'auth-aware deferred slot expects shape',
        '/__semitexa_component_event'             => 'component event handler expects valid event payload',
        '/sitemap.json'                           => 'sitemap aggregation needs configured providers',
        '/sitemap.xml'                            => 'sitemap aggregation needs configured providers',
        // Cycle-11 additions — protected ORM routes also need the seed.
        '/playground/orm/articles-new'                => 'ORM new-article form needs DB',
        '/playground/orm/articles/{id}/edit'          => 'ORM edit form needs seeded article',
        '/playground/orm/articles/{id}/update'        => 'ORM update needs seeded article',
        '/playground/orm/articles/{id}/delete'        => 'ORM delete needs seeded article',
        '/playground/orm/seed'                        => 'ORM seed action expects writable DB',
        // Cycle-11 surfaced: WhoAmI looks up the authenticated user in the
        // platform-user repo; demo smoke user has no DB row.
        '/playground/auth/whoami'                     => 'WhoAmI handler queries user repo for the authenticated id',
    ];

    /**
     * Routes that legitimately return non-2xx as their normal response.
     *
     * @var array<string,int>
     */
    private const EXPECTED_NON_2XX = [
        '/__semitexa/error/500' => 500,
        '/__semitexa/error/404' => 404,
    ];

    /**
     * Map of webhook receiver paths to a dedicated event id used by the
     * test's HMAC-signing helper. The receiver's secretRef is read from
     * env:WEBHOOK_DEMO_SECRET (set in setUp). For the signed-capability
     * route, the test also grants the required service capability before
     * dispatch so it can return 200.
     *
     * @var array<string,string>
     */
    private const WEBHOOK_ROUTES = [
        '/webhook-demo/signed'             => 'evt-auth-smoke-signed',
        '/webhook-demo/signed-capability'  => 'evt-auth-smoke-signed-capability',
    ];

    /**
     * Map of protected-route paths whose #[RequiresPermission] slug we know
     * from the corresponding payload's attribute. The test seeds these into
     * AuthDemoPermissionStore for the smoke user before the per-route loop.
     *
     * Playground RBAC follows the convention `action.name` derived from
     * `/playground/rbac/action/<action-name>` (e.g. admin-tools → admin.tools).
     *
     * @var array<string,string>
     */
    private const KNOWN_PERMISSIONS = [
        '/auth-demo/runtime/protected-with-permission'    => ProtectedPermissionPingPayload::PERMISSION_SLUG,
        '/playground/rbac/action/admin-tools'             => 'admin.tools',
        '/playground/rbac/action/users-manage'            => 'users.manage',
        '/playground/rbac/action/roles-manage'            => 'roles.manage',
        '/playground/rbac/action/content-edit'            => 'content.edit',
        '/playground/rbac/action/content-publish'         => 'content.publish',
        '/playground/rbac/action/content-read'            => 'content.read',
        '/playground/rbac/action/reports-view'            => 'reports.view',
    ];

    private Application $app;

    /** @var list<array<string,mixed>> */
    private array $routes;

    private WebhookReplayStoreInterface $webhookReplayStore;

    protected function setUp(): void
    {
        $_ENV['WEBHOOK_DEMO_SECRET'] = self::SECRET;
        putenv('WEBHOOK_DEMO_SECRET=' . self::SECRET);

        $this->app = new Application();
        $container = ContainerFactory::get();
        /** @var AttributeDiscovery $discovery */
        $discovery = $container->get(AttributeDiscovery::class);
        $discovery->initialize();
        $this->routes = $discovery->getRoutes();
        $this->webhookReplayStore = $container->get(WebhookReplayStoreInterface::class);

        $this->resetAllStores();
        $this->seedKnownGrants();
    }

    protected function tearDown(): void
    {
        $this->resetAllStores();
        unset($_ENV['WEBHOOK_DEMO_SECRET']);
        putenv('WEBHOOK_DEMO_SECRET');
    }

    private function resetAllStores(): void
    {
        AuthContextStore::clear();
        AuthDemoPermissionStore::clear();
        AuthDemoCapabilityStore::clear();
        WebhookDemoEventStore::clear();
        WebhookDemoServiceCapabilityStore::clear();
        $this->webhookReplayStore->clear();
        $this->app->requestScopedContainer->reset();
    }

    /**
     * Pre-populate the demo stores so smoke runs against permission/capability
     * routes can return 200 instead of stopping at 403.
     *
     * For unknown protected routes, NO grant is seeded — those legitimately
     * return 403 (which the loop classifier accepts as PASS for "auth was
     * supplied, just no permission to act").
     */
    private function seedKnownGrants(): void
    {
        AuthDemoPermissionStore::setForUser(self::SMOKE_USER, array_values(self::KNOWN_PERMISSIONS));
        AuthDemoCapabilityStore::setForUser(self::SMOKE_USER, [RuntimeCapability::Ping]);
        WebhookDemoServiceCapabilityStore::setForService(
            SignedCapabilityWebhookPayload::RECEIVER_KEY,
            [WebhookDemoServiceCapability::AcceptSignedEvents],
        );
    }

    private function userToken(): array
    {
        return [AuthDemoStubAuthHandler::HEADER => AuthDemoStubAuthHandler::USER_PREFIX . self::SMOKE_USER];
    }

    private function serviceToken(): array
    {
        return [AuthDemoStubAuthHandler::HEADER => AuthDemoStubAuthHandler::SERVICE_PREFIX . self::SMOKE_SERVICE];
    }

    private function buildRequest(string $method, string $path, array $headers, ?string $body = null): Request
    {
        $h = ['Accept' => 'application/json'];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $h['Content-Type'] = 'application/json';
            $body ??= '{}';
        }
        foreach ($headers as $k => $v) {
            $h[$k] = $v;
        }
        return new Request(
            method: $method,
            uri: $path,
            headers: $h,
            query: [], post: [],
            server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path],
            cookies: [],
            content: $body,
        );
    }

    /**
     * Build a webhook-signed request for the given path + event id.
     * Headers + canonical match the framework verifier expectations.
     */
    private function signedWebhookRequest(string $path, string $eventId): Request
    {
        $ts = time();
        $body = json_encode(['event' => 'auth-smoke', 'route' => $path], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $ts . '.' . $body, self::SECRET);
        return $this->buildRequest('POST', $path, [
            'X-Webhook-Signature' => 'sha256=' . $signature,
            'X-Webhook-Timestamp' => (string) $ts,
            'X-Webhook-Event-Id'  => $eventId,
            'X-Webhook-Event-Type' => 'auth-smoke.event.v1',
        ], $body);
    }

    private function dispatch(Request $request): array
    {
        try {
            $response = $this->app->handleRequest($request);
        } finally {
            $this->app->requestScopedContainer->reset();
            AuthContextStore::clear();
        }
        $body = json_decode($response->getContent(), true);
        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($body) ? $body : null,
            'snippet' => substr($response->getContent(), 0, 200),
        ];
    }

    /**
     * Substitute path placeholders with smoke-safe values.
     * Mirrors the helper used by AllRoutesRuntimeSmokeTest.
     */
    private function smokePath(string $path): string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $m): string {
                $name = strtolower($m[1]);
                return match (true) {
                    str_contains($name, 'uuid')      => '00000000-0000-0000-0000-000000000001',
                    str_contains($name, 'id')        => '1',
                    str_contains($name, 'token')     => 'smoke-token',
                    str_contains($name, 'name'),
                    str_contains($name, 'slug'),
                    str_contains($name, 'key')       => 'smoke-test',
                    str_contains($name, 'part')      => '1',
                    default                          => 'smoke-test',
                };
            },
            $path,
        ) ?? $path;
    }

    private function isWebhookRoute(string $path): bool
    {
        return isset(self::WEBHOOK_ROUTES[$path]);
    }

    private function checkRouteRegistered(string $method, string $path): bool
    {
        try {
            $registry = ContainerFactory::get()->get(\Semitexa\Core\Discovery\RouteRegistry::class);
            return $registry->find($path, $method) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function classifyResponse(
        int $status,
        string $bodySnippet,
        string $method,
        string $rawPath,
        string $smokePath,
        PayloadAccessType $access,
        string $authStrategy,
        string $class,
    ): ?string {
        // Expected non-2xx pinned routes (error pages).
        if (($self_status = self::EXPECTED_NON_2XX[$rawPath] ?? null) !== null && $self_status === $status) {
            return null;
        }

        if ($status >= 500) {
            if (isset(self::NEEDS_FIXTURES[$rawPath])
                && (str_contains($bodySnippet, 'An unexpected error occurred')
                    || str_contains($bodySnippet, 'Internal Server Error'))
            ) {
                return null;
            }
            return sprintf(
                '%s %s [%s] [access=%s,auth=%s]: HTTP %d (5xx) — body: %s',
                $method, $smokePath, self::shortClass($class), $access->value, $authStrategy, $status, $bodySnippet,
            );
        }

        if ($status === 404) {
            if ((self::EXPECTED_NON_2XX[$rawPath] ?? null) === 404) {
                return null;
            }
            // Handler-controlled 404 (NotFoundException + ExceptionMapper) is acceptable
            // as long as the registry actually matched the path.
            if ($this->checkRouteRegistered($method, $smokePath)) {
                return null;
            }
            return sprintf(
                '%s %s [%s] [access=%s,auth=%s]: HTTP 404 (registry::find did NOT match) — body: %s',
                $method, $smokePath, self::shortClass($class), $access->value, $authStrategy, $bodySnippet,
            );
        }

        // The hard contract: when valid auth was supplied for the route's
        // declared access type, 401 is unexpected.
        if ($status === 401 && $access !== PayloadAccessType::Public) {
            return sprintf(
                '%s %s [%s] [access=%s,auth=%s]: 401 with valid auth — auth pipeline regression?',
                $method, $smokePath, self::shortClass($class), $access->value, $authStrategy,
            );
        }

        // Public route that 401'd is also a regression.
        if ($status === 401 && $access === PayloadAccessType::Public) {
            return sprintf(
                '%s %s [%s]: PUBLIC route 401\'d under auth header (gate misclassification)',
                $method, $smokePath, self::shortClass($class),
            );
        }

        return null;
    }

    // ────────────────────────────────────────────────────────────────────
    // Authenticated route scenarios
    // ────────────────────────────────────────────────────────────────────

    #[Test]
    public function every_protected_route_enters_runtime_with_user_token_and_does_not_401_or_500(): void
    {
        $failures = [];
        $smoked = 0;
        foreach ($this->routes as $route) {
            $access = $route['accessType'] ?? null;
            if (!$access instanceof PayloadAccessType || $access !== PayloadAccessType::Protected) {
                continue;
            }
            $path = (string) $route['path'];
            if (isset(self::SKIP[$path])) {
                continue;
            }
            $methods = $route['methods'] ?? ['GET'];
            $method = strtoupper((string) ($methods[0] ?? 'GET'));
            $smokePath = $this->smokePath($path);
            $class = (string) ($route['class'] ?? '?');

            $this->seedKnownGrants(); // re-seed in case prior test fired clear

            try {
                $out = $this->dispatch($this->buildRequest($method, $smokePath, $this->userToken()));
            } catch (\Throwable $e) {
                $failures[] = sprintf(
                    '%s %s [%s] [access=protected,auth=user]: UNCAUGHT %s — %s',
                    $method, $smokePath, self::shortClass($class), $e::class, substr($e->getMessage(), 0, 200),
                );
                continue;
            }

            $smoked++;
            $fail = $this->classifyResponse(
                $out['status'], $out['snippet'], $method, $path, $smokePath, $access, 'user-token', $class,
            );
            if ($fail !== null) {
                $failures[] = $fail;
            }
        }

        self::assertSame(
            [],
            $failures,
            sprintf("smoked: %d protected routes\n  - %s", $smoked, implode("\n  - ", $failures)),
        );
    }

    #[Test]
    public function every_service_route_enters_runtime_with_correct_auth_and_does_not_401_or_500(): void
    {
        $failures = [];
        $smoked = 0;
        foreach ($this->routes as $route) {
            $access = $route['accessType'] ?? null;
            if (!$access instanceof PayloadAccessType || $access !== PayloadAccessType::Service) {
                continue;
            }
            $path = (string) $route['path'];
            if (isset(self::SKIP[$path])) {
                continue;
            }
            $methods = $route['methods'] ?? ['GET'];
            $method = strtoupper((string) ($methods[0] ?? 'GET'));
            $smokePath = $this->smokePath($path);
            $class = (string) ($route['class'] ?? '?');

            $this->seedKnownGrants();

            // Webhook routes get a real HMAC-signed request; non-webhook
            // service routes get the AuthDemoStubAuthHandler service token.
            if ($this->isWebhookRoute($path)) {
                $request = $this->signedWebhookRequest($path, self::WEBHOOK_ROUTES[$path] . '-' . uniqid());
                $authStrategy = 'webhook-hmac';
            } else {
                $request = $this->buildRequest($method, $smokePath, $this->serviceToken());
                $authStrategy = 'service-token';
            }

            try {
                $out = $this->dispatch($request);
            } catch (\Throwable $e) {
                $failures[] = sprintf(
                    '%s %s [%s] [access=service,auth=%s]: UNCAUGHT %s — %s',
                    $method, $smokePath, self::shortClass($class), $authStrategy, $e::class, substr($e->getMessage(), 0, 200),
                );
                continue;
            }

            $smoked++;
            $fail = $this->classifyResponse(
                $out['status'], $out['snippet'], $method, $path, $smokePath, $access, $authStrategy, $class,
            );
            if ($fail !== null) {
                $failures[] = $fail;
            }
        }

        self::assertSame(
            [],
            $failures,
            sprintf("smoked: %d service routes\n  - %s", $smoked, implode("\n  - ", $failures)),
        );
    }

    #[Test]
    public function public_routes_with_user_auth_header_do_not_break(): void
    {
        // Sample subset (10 random public routes) so the test stays fast.
        $publicRoutes = array_values(array_filter(
            $this->routes,
            static fn (array $r): bool => ($r['accessType'] ?? null) === PayloadAccessType::Public,
        ));
        // Pin a deterministic subset by sorting by path.
        usort($publicRoutes, static fn ($a, $b) => strcmp($a['path'], $b['path']));
        $sample = array_slice($publicRoutes, 0, 10);

        $failures = [];
        foreach ($sample as $route) {
            $path = (string) $route['path'];
            if (isset(self::SKIP[$path]) || isset(self::NEEDS_FIXTURES[$path])) {
                continue;
            }
            $method = strtoupper((string) ($route['methods'][0] ?? 'GET'));
            $smokePath = $this->smokePath($path);
            $class = (string) ($route['class'] ?? '?');

            $out = $this->dispatch($this->buildRequest($method, $smokePath, $this->userToken()));
            // Public must not 401 just because we sent a header.
            if ($out['status'] === 401) {
                $failures[] = sprintf(
                    '%s %s [%s]: public route 401\'d under user-token header',
                    $method, $smokePath, self::shortClass($class),
                );
            }
            if ($out['status'] >= 500
                && (self::EXPECTED_NON_2XX[$path] ?? null) !== $out['status']
            ) {
                $failures[] = sprintf(
                    '%s %s [%s]: public route 5xx under user-token header — body: %s',
                    $method, $smokePath, self::shortClass($class), $out['snippet'],
                );
            }
        }
        self::assertSame([], $failures);
    }

    // ────────────────────────────────────────────────────────────────────
    // Cross-domain rejection — auth/webhook/capability boundaries hold
    // ────────────────────────────────────────────────────────────────────

    #[Test]
    public function user_token_does_not_satisfy_any_service_route(): void
    {
        $failures = [];
        foreach ($this->routes as $route) {
            $access = $route['accessType'] ?? null;
            if ($access !== PayloadAccessType::Service) {
                continue;
            }
            $path = (string) $route['path'];
            if (isset(self::SKIP[$path])) {
                continue;
            }
            $method = strtoupper((string) ($route['methods'][0] ?? 'GET'));
            $smokePath = $this->smokePath($path);

            $out = $this->dispatch($this->buildRequest($method, $smokePath, $this->userToken()));
            if ($out['status'] === 200 || $out['status'] === 201 || $out['status'] === 204) {
                $failures[] = sprintf(
                    'service route %s satisfied by user token (HTTP %d) — user-vs-service boundary regression',
                    $smokePath, $out['status'],
                );
            }
        }
        self::assertSame([], $failures);
    }

    #[Test]
    public function service_token_does_not_satisfy_any_protected_route(): void
    {
        $failures = [];
        foreach ($this->routes as $route) {
            $access = $route['accessType'] ?? null;
            if ($access !== PayloadAccessType::Protected) {
                continue;
            }
            $path = (string) $route['path'];
            if (isset(self::SKIP[$path]) || isset(self::NEEDS_FIXTURES[$path])) {
                continue;
            }
            $method = strtoupper((string) ($route['methods'][0] ?? 'GET'));
            $smokePath = $this->smokePath($path);

            $out = $this->dispatch($this->buildRequest($method, $smokePath, $this->serviceToken()));
            if ($out['status'] === 200 || $out['status'] === 201 || $out['status'] === 204) {
                $failures[] = sprintf(
                    'protected route %s satisfied by service token (HTTP %d) — user-vs-service boundary regression',
                    $smokePath, $out['status'],
                );
            }
        }
        self::assertSame([], $failures);
    }

    #[Test]
    public function generic_service_token_does_not_satisfy_any_webhook_receiver_route(): void
    {
        // Webhook receivers must be authenticated ONLY by signature
        // verification. A generic AuthDemoStubAuthHandler service token
        // must NOT bypass WebhookAuthHandler.
        $failures = [];
        foreach (array_keys(self::WEBHOOK_ROUTES) as $path) {
            $method = 'POST';
            $out = $this->dispatch($this->buildRequest($method, $path, $this->serviceToken()));
            if ($out['status'] !== 401) {
                $failures[] = sprintf(
                    'webhook receiver %s did not 401 when given a generic service token (got %d) — webhook-signature boundary regression',
                    $path, $out['status'],
                );
            }
        }
        self::assertSame([], $failures);
    }

    // ────────────────────────────────────────────────────────────────────
    // State leakage (auth identity must not flow between dispatches)
    // ────────────────────────────────────────────────────────────────────

    #[Test]
    public function user_auth_does_not_leak_into_a_later_anonymous_protected_request(): void
    {
        $first = $this->dispatch($this->buildRequest('GET', '/auth-demo/runtime/protected', $this->userToken()));
        self::assertSame(200, $first['status']);

        $second = $this->dispatch($this->buildRequest('GET', '/auth-demo/runtime/protected', []));
        self::assertSame(401, $second['status'], 'user identity leaked into later anonymous request');
        self::assertNull(AuthContextStore::getUser());
    }

    #[Test]
    public function service_auth_does_not_leak_into_a_later_protected_user_request(): void
    {
        // Service authenticates against /auth-demo/runtime/service.
        $first = $this->dispatch($this->buildRequest('GET', '/auth-demo/runtime/service', $this->serviceToken()));
        self::assertSame(200, $first['status']);

        // Anonymous protected request must 401.
        $second = $this->dispatch($this->buildRequest('GET', '/auth-demo/runtime/protected', []));
        self::assertSame(401, $second['status']);
    }

    #[Test]
    public function webhook_signed_request_does_not_leak_service_principal_into_later_routes(): void
    {
        // Send a real signed request to the signed-webhook receiver.
        $request = $this->signedWebhookRequest('/webhook-demo/signed', 'evt-leak-' . uniqid());
        $first = $this->dispatch($request);
        self::assertSame(200, $first['status']);

        // Anonymous protected user route must still 401.
        $second = $this->dispatch($this->buildRequest('GET', '/auth-demo/runtime/protected', []));
        self::assertSame(401, $second['status']);

        // Public route must work without auth, returning 200 (or its
        // controlled response). It MUST NOT carry a leftover service
        // principal forward.
        $third = $this->dispatch($this->buildRequest('GET', '/auth-demo/runtime/public', []));
        self::assertSame(200, $third['status']);
        self::assertNull($third['body']['principal'] ?? null);
    }

    // ────────────────────────────────────────────────────────────────────
    // Permission/capability behavior with grants supplied
    // ────────────────────────────────────────────────────────────────────

    #[Test]
    public function known_demo_permission_route_returns_200_when_grant_seeded(): void
    {
        // smoke-user already has the permission via seedKnownGrants().
        $out = $this->dispatch($this->buildRequest(
            'GET',
            '/auth-demo/runtime/protected-with-permission',
            $this->userToken(),
        ));
        self::assertSame(200, $out['status']);
    }

    #[Test]
    public function known_demo_capability_route_returns_200_when_grant_seeded(): void
    {
        // smoke-user already has the capability via seedKnownGrants().
        $out = $this->dispatch($this->buildRequest(
            'GET',
            '/auth-demo/runtime/protected-with-capability',
            $this->userToken(),
        ));
        self::assertSame(200, $out['status']);
    }

    #[Test]
    public function unknown_permission_route_returns_403_when_grant_missing_not_500(): void
    {
        // Wipe grants so smoke-user has nothing.
        AuthDemoPermissionStore::setForUser(self::SMOKE_USER, []);
        AuthDemoCapabilityStore::setForUser(self::SMOKE_USER, []);

        $out = $this->dispatch($this->buildRequest(
            'GET',
            '/auth-demo/runtime/protected-with-permission',
            $this->userToken(),
        ));
        self::assertSame(403, $out['status'], 'missing grant must produce 403 not 500');
    }

    private static function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return (string) end($parts);
    }
}
