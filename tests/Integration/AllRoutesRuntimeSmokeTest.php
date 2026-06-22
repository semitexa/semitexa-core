<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Authorization\Application\Service\PayloadAccessPolicyResolver;
use Semitexa\Core\Application;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Request;
use Semitexa\Core\Support\TenantModuleScopeResolver;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * Mechanical smoke pass: dispatch every active discovered route through
 * Application::handleRequest with a minimal synthetic Request, and assert
 * the route either returns a controlled status (2xx/3xx/4xx) or a documented
 * controlled error — never a 500, fatal, or unexpected 404.
 *
 * Latent bugs in the auth pipeline (e.g. AuthorizationListener constructing
 * AccessPolicy with the wrong shape, Authorizer accessing isPublic as a
 * property) only surface when something drives the full pipeline. This file
 * is the broad insurance: every route in the project gets one dispatch
 * through the real runtime so the same class of bug cannot hide in handlers
 * that no test ever drove.
 *
 * Pass criteria per route:
 *   2xx, 3xx                — handler ran and produced a controlled response
 *   401 on protected/service — expected (auth required)
 *   401 on public            — UNEXPECTED but not necessarily a fatal; reported
 *   403, 404 on _domain_     — controlled (e.g. a /widgets/{id} where smoke id 1 doesn't exist)
 *   400, 415, 422            — controlled hydration / negotiation / validation
 * Fail criteria:
 *   500                      — uncaught framework error
 *   uncaught exception       — handler escaped without being mapped
 *   404 from RouteExecutor   — route discovered but pipeline can't reach it
 *
 * Skipped routes are intentional and documented inline: SSE / streaming
 * endpoints would block the test process indefinitely; the framework's
 * dedicated streaming tests cover them.
 */
final class AllRoutesRuntimeSmokeTest extends TestCase
{
    /**
     * Routes intentionally not driven through Application::handleRequest in
     * a single-shot smoke test, with the reason. Keep this list minimal and
     * justified — every entry here is a coverage gap that should be reduced
     * by a dedicated test elsewhere.
     *
     * @var array<string,string> path => reason
     */
    private const SKIP = [
        '/__semitexa_kiss'    => 'long-lived SSE keep-alive stream; would block.',
        '/__semitexa_hug'     => 'SSR fallback streamed via TransportType::Sse; would block.',
    ];

    /**
     * Routes that legitimately return non-2xx as their normal response — error
     * page renderers whose entire job is to produce an N00 page. Smoking them
     * must not flag the return status as a fatal.
     *
     * @var array<string,int> path => expected status code
     */
    private const EXPECTED_NON_2XX = [
        '/__semitexa/error/500' => 500,
        '/__semitexa/error/404' => 404,
    ];

    /**
     * Routes that need domain fixtures (database seeds, tenant context,
     * etc.) to produce a happy 200 but should still complete the runtime
     * pipeline cleanly. The smoke test treats 5xx from these as a fixture
     * gap, not a framework bug — provided the body
     * is the controlled "An unexpected error occurred" envelope (which
     * means ExceptionMapper caught a thrown handler exception cleanly,
     * NOT that the framework itself crashed).
     *
     * @var array<string,string> path => why fixtures are needed
     */
    private const NEEDS_FIXTURES = [
        '/playground/customers'           => 'lists customers from ORM seed; no test seed in env',
        '/playground/orm'                 => 'ORM landing reads platform-user db; needs tenant + seed',
        '/playground/orm/articles/{id}'   => 'ORM article lookup needs a seeded article id',
        '/ssr-polygon/deferred/auth-aware' => 'auth-aware deferred slot expects an auth context shape that anonymous smoke does not satisfy',
        '/__semitexa_component_event'      => 'component event handler expects a valid event payload; empty {} is rejected at handler level',
        '/sitemap.json'                   => 'sitemap aggregation reaches into multiple modules; needs configured sitemap providers',
        '/sitemap.xml'                    => 'sitemap aggregation reaches into multiple modules; needs configured sitemap providers',
    ];

    private Application $app;

    /** @var list<array<string,mixed>> */
    private array $routes;

    protected function setUp(): void
    {
        // Building the Application also boots the container and runs route
        // discovery — same code path as the production worker.
        $this->app = new Application();
        $container = ContainerFactory::get();

        /** @var AttributeDiscovery $discovery */
        $discovery = $container->get(AttributeDiscovery::class);
        $discovery->initialize();

        // One Way: the production worker also warms
        // ResourceMetadataRegistry at WorkerStartFinalize
        // (WarmResourceMetadataListener); the in-process boot has no Swoole
        // lifecycle, so warm it here the same way DumpOpenApiCommand does for
        // its non-Swoole context. Without this, every route that renders
        // through the Resource registry 500s in the smoke env regardless of
        // fixtures — an environment under-boot, not a route defect.
        /** @var \Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry $registry */
        $registry = $container->get(\Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry::class);
        $registry->ensureWarmed(
            discovery:  $container->get(ClassDiscovery::class),
            cache:      $container->get(\Semitexa\Core\Resource\Metadata\ResourceMetadataCacheFile::class),
            production: false,
        );

        $this->routes = $discovery->getRoutes();
        AuthContextStore::clear();
    }

    protected function tearDown(): void
    {
        AuthContextStore::clear();
        $this->app->requestScopedContainer->reset();
    }

    #[Test]
    public function inventory_has_at_least_the_known_minimum_route_count(): void
    {
        // Sanity floor — if discovery silently drops a swathe of routes, the
        // smoke loop would silently shrink too. The hard floor is conservative
        // (50) so legitimate route deletions don't false-positive on every
        // change; a regression that drops 30+ routes will trip this.
        self::assertGreaterThanOrEqual(
            50,
            count($this->routes),
            'route discovery returned a suspiciously small inventory — investigate ClassDiscovery or AttributeDiscovery before chasing other failures',
        );
    }

    #[Test]
    public function every_discovered_route_has_an_explicit_access_type(): void
    {
        $missing = [];
        foreach ($this->routes as $route) {
            $access = $route['accessType'] ?? null;
            if (!$access instanceof PayloadAccessType) {
                $missing[] = ($route['methods'][0] ?? '?') . ' ' . ($route['path'] ?? '?')
                    . ' [' . ($route['class'] ?? '?') . ']';
            }
        }
        self::assertSame(
            [],
            $missing,
            "Routes missing PayloadAccessType (every payload must declare one):\n  - " . implode("\n  - ", $missing),
        );
    }

    #[Test]
    public function every_route_class_is_reflectable_and_lists_the_attribute(): void
    {
        // Defends against the silent-skip pattern: a payload with a broken
        // attribute argument can be "discovered" (its class is in the
        // classmap and reflection works) but produce no route in the
        // registry because attribute instantiation throws inside
        // ClassDiscovery::findClassesWithAttributeInstanceof. This test
        // re-walks the same discovery surface and verifies every class with
        // an AbstractPayloadRoute attribute also appears in the registered
        // route list.
        $container = ContainerFactory::get();
        /** @var ClassDiscovery $cd */
        $cd = $container->get(ClassDiscovery::class);
        $cls = $cd->findClassesWithAttributeInstanceof(AbstractPayloadRoute::class);

        $registered = array_flip(array_map(
            static fn (array $r): string => (string) ($r['class'] ?? ''),
            $this->routes,
        ));

        $missing = [];
        foreach ($cls as $class) {
            if (!isset($registered[$class])) {
                $missing[] = $class;
            }
        }

        // Allow inactive-module payloads to be filtered out — the registry
        // only registers payloads from active modules. The check below
        // catches the dangerous case: an ACTIVE module payload that
        // disappears between class discovery and route registration.
        $missingActive = [];
        $resolver = new PayloadAccessPolicyResolver();
        foreach ($missing as $class) {
            if (!class_exists($class)) {
                continue;
            }
            try {
                $payload = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                // If we can resolve the access type, the attribute is live —
                // its absence from the registry is then a real silent skip.
                $resolver->accessType($payload);
                $missingActive[] = $class;
            } catch (\Throwable) {
                // attribute could not even be inspected — that's the silent-skip
                // failure mode this test is designed to catch.
                $missingActive[] = $class . ' (uninspectable)';
            }
        }

        // At minimum the active demo modules must be registered. Inactive vendor
        // modules may legitimately be in the classmap but not in the registry.
        $authDemoMissing = array_filter(
            $missingActive,
            static fn (string $c): bool => str_contains($c, '\\AuthDemo\\')
                || str_contains($c, '\\EventsDemo\\')
                || str_contains($c, '\\ExceptionMapperDemo\\')
                || str_contains($c, '\\Hello\\')
                || str_contains($c, '\\PipelineTest\\'),
        );
        self::assertSame(
            [],
            $authDemoMissing,
            "Active demo-module payloads silently dropped from route registry:\n  - " . implode("\n  - ", $authDemoMissing),
        );
    }

    #[Test]
    public function every_active_route_smokes_through_the_runtime_without_a_500_or_fatal(): void
    {
        // ONE big test that drives every route and reports ALL failures at once.
        // Per-route subtests would fragment failures; a single accumulator gives
        // the operator one summary to act on.
        $failures = [];
        $skipped = [];
        $smoked = 0;

        foreach ($this->routes as $route) {
            $path = (string) ($route['path'] ?? '');
            $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
            $accessType = $route['accessType'] ?? null;
            $class = (string) ($route['class'] ?? '?');
            $transport = (string) ($route['transport'] ?? 'http');

            if (isset(self::SKIP[$path])) {
                $skipped[] = $path . ' :: ' . self::SKIP[$path];
                continue;
            }
            if ($transport === 'sse') {
                // Belt-and-braces: any route declared with TransportType::Sse
                // gets skipped automatically, even if not in the path skip list.
                $skipped[] = $path . ' :: declared TransportType::Sse';
                continue;
            }

            // Pick the first method — smoke is not exhaustive, one method per
            // route is enough to detect 404/500/fatal regressions.
            $method = strtoupper(is_array($methods) ? (string) ($methods[0] ?? 'GET') : (string) $methods);
            $smokePath = $this->substitutePathParams($path);

            $request = $this->buildSmokeRequest($method, $smokePath);

            try {
                $response = $this->app->handleRequest($request);
                $status = $response->getStatusCode();
                $bodySnippet = substr($response->getContent(), 0, 200);
            } catch (\Throwable $e) {
                $failures[] = sprintf(
                    "%s %s [%s] [access=%s]: UNCAUGHT %s — %s",
                    $method,
                    $smokePath,
                    self::shortClass($class),
                    $accessType instanceof PayloadAccessType ? $accessType->value : '?',
                    $e::class,
                    substr($e->getMessage(), 0, 200),
                );
                continue;
            } finally {
                $this->app->requestScopedContainer->reset();
                AuthContextStore::clear();
            }

            $smoked++;

            if ($status >= 500) {
                // The error-page renderer must return its declared 5xx status — that's its job.
                if ((self::EXPECTED_NON_2XX[$path] ?? null) === $status) {
                    continue;
                }
                if (isset(self::NEEDS_FIXTURES[$path])) {
                    // Controlled 5xx from ExceptionMapper (handler threw,
                    // mapper caught) is an acceptable smoke outcome for a
                    // route that requires domain fixtures. The body is the
                    // generic envelope, not a framework crash.
                    if (str_contains($bodySnippet, 'An unexpected error occurred')
                        || str_contains($bodySnippet, 'Internal Server Error')
                    ) {
                        continue;
                    }
                }
                $failures[] = sprintf(
                    "%s %s [%s] [access=%s]: HTTP %d (5xx) — body: %s",
                    $method,
                    $smokePath,
                    self::shortClass($class),
                    $accessType instanceof PayloadAccessType ? $accessType->value : '?',
                    $status,
                    $bodySnippet,
                );
                continue;
            }

            if ($status === 404) {
                // Routes that ARE the 404 page legitimately return 404.
                if ((self::EXPECTED_NON_2XX[$path] ?? null) === 404) {
                    continue;
                }
                // Verify the route IS registered for this smoke URL. If
                // RouteRegistry::find() can match the path back to a
                // registered route, the 404 came from the HANDLER (file
                // not on disk, record not in DB, etc.) — controlled
                // domain response. If not, the registry never matched
                // the path → that's the dangerous "discovered but
                // unreachable" failure mode this test guards against.
                if ($this->routeIsRegisteredForPath($method, $smokePath, isset($route['module']) ? (string) $route['module'] : null)) {
                    continue;
                }
                $failures[] = sprintf(
                    "%s %s [%s] [access=%s]: HTTP 404 (route registered but registry::find() did NOT match the smoke path) — body: %s",
                    $method,
                    $smokePath,
                    self::shortClass($class),
                    $accessType instanceof PayloadAccessType ? $accessType->value : '?',
                    $bodySnippet,
                );
                continue;
            }

            if ($status === 401 && $accessType === PayloadAccessType::Public) {
                // Cycle-4 contract: public routes must NOT be guarded by auth.
                // A 401 here means the auth gate misclassified the route.
                $failures[] = sprintf(
                    "%s %s [%s]: PUBLIC route returned 401 — gate misclassification? body: %s",
                    $method,
                    $smokePath,
                    self::shortClass($class),
                    $bodySnippet,
                );
                continue;
            }

            // Every other status (200/201/204/3xx, 400/403/405/415/422, plus
            // 401 on protected/service) is a controlled response — pass.
        }

        $summary = sprintf(
            "smoked: %d, skipped: %d, failed: %d, total discovered: %d",
            $smoked,
            count($skipped),
            count($failures),
            count($this->routes),
        );

        self::assertSame(
            [],
            $failures,
            "Runtime smoke failures ({$summary}):\n\n  - " . implode("\n  - ", $failures),
        );
    }

    #[Test]
    public function repeated_smoke_dispatches_do_not_leak_auth_state(): void
    {
        // Drive several routes back-to-back in this single test process and
        // confirm the per-request reset (PerRequestStateRegistry) keeps the
        // AuthContext clean between dispatches. If the per-request lifecycle
        // ever regresses, this test will fail with `getUser() !== null`.
        $samples = [
            'GET /auth-demo/runtime/public',
            'GET /auth-demo/runtime/protected',
            'GET /auth-demo/runtime/service',
            'GET /auth-demo/runtime/protected-with-permission',
            'GET /auth-demo/runtime/protected-with-capability',
        ];

        foreach ($samples as $sample) {
            [$method, $path] = explode(' ', $sample, 2);
            $this->app->handleRequest($this->buildSmokeRequest($method, $path));
            self::assertNull(
                AuthContextStore::getUser(),
                "auth context leaked after dispatching {$sample}",
            );
            $this->app->requestScopedContainer->reset();
        }
    }

    private function buildSmokeRequest(string $method, string $path): Request
    {
        $headers = ['Accept' => 'application/json'];
        $content = null;
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers['Content-Type'] = 'application/json';
            $content = '{}';
        }
        return new Request(
            method: $method,
            uri: $path,
            headers: $headers,
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path],
            cookies: [],
            content: $content,
        );
    }

    /**
     * Replace common Symfony-style path placeholders with safe smoke values.
     * Falls back to "smoke-test" for unknown names so unfamiliar params still
     * produce a syntactically-valid URL.
     */
    private function substitutePathParams(string $path): string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/',
            static function (array $m): string {
                $name = strtolower($m[1]);
                return match (true) {
                    str_contains($name, 'id') && !str_contains($name, 'slug')
                        && !str_contains($name, 'uuid') => '1',
                    str_contains($name, 'uuid')         => '00000000-0000-0000-0000-000000000001',
                    str_contains($name, 'token')        => 'smoke-token',
                    str_contains($name, 'name')
                        || str_contains($name, 'slug')
                        || str_contains($name, 'key')   => 'smoke-test',
                    str_contains($name, 'part')         => '1',
                    default                              => 'smoke-test',
                };
            },
            $path,
        ) ?? $path;
    }

    private static function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return (string) end($parts);
    }

    /**
     * Returns true if RouteRegistry::find() can match the synthetic smoke URL
     * to any registered route. Used in the 404 classifier to distinguish
     * "handler returned 404" (controlled) from "registry never matched the
     * smoke path" (the silent-discovery-failure mode).
     *
     * RouteRegistry::find() is tenant-scoped: it returns only routes whose
     * module is active for the currently-resolved tenant. The smoke request is
     * host-less, so the ambient lookup below resolves the default tenant and
     * can only see global / default-tenant routes. In a multi-tenant install
     * (the release clone deliberately runs site/demo/os/platform on isolated
     * domains) a route owned by a non-default tenant — e.g. the demo tenant's
     * /demo/* pages — then looks "discovered but unreachable", a false positive
     * (the route serves 200 under its own host). So when the ambient lookup
     * misses, re-check find() under each tenant that owns the route's module.
     */
    private function routeIsRegisteredForPath(string $method, string $path, ?string $module = null): bool
    {
        $container = ContainerFactory::get();
        /** @var \Semitexa\Core\Discovery\RouteRegistry $registry */
        $registry = $container->get(\Semitexa\Core\Discovery\RouteRegistry::class);

        // Ambient / host-less lookup — covers global and default-tenant routes
        // (unchanged behaviour).
        try {
            if ($registry->find($path, $method) !== null) {
                return true;
            }
        } catch (\Throwable) {
            // fall through to tenant-scoped retries
        }

        $scopes = TenantModuleScopeResolver::scopesForModule($module);
        if ($scopes === []) {
            return false;
        }

        /** @var TenantContextStoreInterface $store */
        $store = $container->get(TenantContextStoreInterface::class);
        $previous = $store->tryGet();
        try {
            foreach ($scopes as $tenantId) {
                $store->set(TenantContext::fromResolution($tenantId, 'smoke-test'));
                try {
                    if ($registry->find($path, $method) !== null) {
                        return true;
                    }
                } catch (\Throwable) {
                    // try the next owning tenant
                }
            }
            return false;
        } finally {
            if ($previous !== null) {
                $store->set($previous);
            } else {
                $store->clear();
            }
        }
    }
}
