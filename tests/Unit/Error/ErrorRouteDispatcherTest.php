<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Error;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Environment;
use Semitexa\Core\Error\ErrorPageContext;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

final class ErrorRouteDispatcherTest extends TestCase
{
    #[Test]
    public function html_404_without_custom_route_returns_fallback_html(): void
    {
        $routes = $this->createMock(RouteRegistry::class);
        $routes->expects($this->once())
            ->method('findByNameTyped')
            ->with(ErrorRouteDispatcher::ROUTE_NAME_404)
            ->willReturn(null);

        $dispatcher = $this->makeDispatcher($routes);
        $request = $this->makeHtmlRequest('/missing');

        $response = $dispatcher->dispatchStatus(404, $request);

        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type']);
        self::assertStringContainsString('404 Not Found', $response->getContent());
    }

    #[Test]
    public function html_500_uses_named_error_route_and_exposes_error_context(): void
    {
        $errorRoute = $this->makeDiscoveredRoute(ErrorRouteDispatcher::ROUTE_NAME_500);
        $routes = $this->createMock(RouteRegistry::class);
        $routes->expects($this->once())
            ->method('findByNameTyped')
            ->with(ErrorRouteDispatcher::ROUTE_NAME_500)
            ->willReturn($errorRoute);

        $container = new InMemoryContainer();
        $requestScopedContainer = new RequestScopedContainer($container);
        $dispatcher = new ErrorRouteDispatcher(
            routeRegistry: $routes,
            requestScopedContainer: $requestScopedContainer,
            container: $container,
            authBootstrapper: null,
            environment: $this->makeEnvironment(debug: true),
            routeExecutor: function (DiscoveredRoute $route, Request $_request) use ($requestScopedContainer): HttpResponse {
                /** @var ErrorPageContext $context */
                $context = $requestScopedContainer->get(ErrorPageContext::class);
                $routeName = $route->name;
                if (!is_string($routeName)) {
                    throw new \RuntimeException('Error route name must be a string.');
                }

                return HttpResponse::html(
                    '<h1>' . $routeName . '</h1><p>' . $context->requestPath . '</p>',
                    200,
                );
            },
        );

        $response = $dispatcher->dispatchThrowable(
            new \RuntimeException('Boom'),
            $this->makeHtmlRequest('/broken'),
            ['name' => 'demo.page'],
        );

        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString(ErrorRouteDispatcher::ROUTE_NAME_500, $response->getContent());
        self::assertStringContainsString('/broken', $response->getContent());
    }

    #[Test]
    public function recursion_guard_falls_back_to_plain_500_html(): void
    {
        $routes = $this->createMock(RouteRegistry::class);
        $routes->expects($this->never())->method('findByNameTyped');

        $dispatcher = $this->makeDispatcher($routes, debug: true);

        $response = $dispatcher->dispatchThrowable(
            new \RuntimeException('Broken error page'),
            $this->makeHtmlRequest('/broken'),
            ['name' => ErrorRouteDispatcher::ROUTE_NAME_500],
        );

        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('500 Internal Server Error', $response->getContent());
        self::assertStringNotContainsString(ErrorRouteDispatcher::ROUTE_NAME_500, $response->getContent());
    }

    #[Test]
    public function non_html_request_keeps_existing_non_html_flow(): void
    {
        $routes = $this->createMock(RouteRegistry::class);
        $routes->expects($this->never())->method('findByNameTyped');

        $dispatcher = $this->makeDispatcher($routes);
        $request = new Request('GET', '/missing', ['Accept' => 'application/json'], [], [], [], []);

        self::assertNull($dispatcher->dispatchStatus(404, $request));
    }

    #[Test]
    public function domain_exception_message_is_surfaced_as_public_error_text(): void
    {
        $routes = $this->createMock(RouteRegistry::class);
        // 403 has no named error route, so the dispatcher renders directly.
        $routes->expects($this->never())->method('findByNameTyped');

        $dispatcher = $this->makeDispatcher($routes);
        $request = $this->makeHtmlRequest('/playground/rbac/action/users-manage');

        $response = $dispatcher->dispatchThrowable(
            new AccessDeniedException('Missing permission: users.manage'),
            $request,
            ['name' => 'Playground.RbacAction.usersManage'],
        );

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());

        $body = $response->getContent();
        self::assertStringContainsString('403 Forbidden', $body);
        self::assertStringContainsString('Missing permission: users.manage', $body);
        // Pin the regression: the generic placeholder must never replace
        // a domain exception's user-facing message.
        self::assertStringNotContainsString('An unexpected error occurred', $body);
    }

    #[Test]
    public function unknown_exception_keeps_generic_public_message(): void
    {
        $routes = $this->createMock(RouteRegistry::class);
        $routes->expects($this->once())
            ->method('findByNameTyped')
            ->with(ErrorRouteDispatcher::ROUTE_NAME_500)
            ->willReturn(null);

        $dispatcher = $this->makeDispatcher($routes);

        $response = $dispatcher->dispatchThrowable(
            new \RuntimeException('Database password is "hunter2"'),
            $this->makeHtmlRequest('/broken'),
            ['name' => 'demo.page'],
        );

        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('An unexpected error occurred', $response->getContent());
        // Sensitive raw exception text must NOT leak through the public message.
        self::assertStringNotContainsString('hunter2', $response->getContent());
    }

    #[Test]
    public function wildcard_accept_header_prefers_html_error_flow(): void
    {
        $routes = $this->createMock(RouteRegistry::class);
        $routes->expects($this->once())
            ->method('findByNameTyped')
            ->with(ErrorRouteDispatcher::ROUTE_NAME_404)
            ->willReturn(null);

        $dispatcher = $this->makeDispatcher($routes);
        $request = new Request('GET', '/missing', ['Accept' => '*/*'], [], [], [], []);

        $response = $dispatcher->dispatchStatus(404, $request);

        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    private function makeDispatcher(RouteRegistry $routes, bool $debug = false): ErrorRouteDispatcher
    {
        return new ErrorRouteDispatcher(
            routeRegistry: $routes,
            requestScopedContainer: new RequestScopedContainer(new InMemoryContainer()),
            container: new InMemoryContainer(),
            authBootstrapper: null,
            environment: $this->makeEnvironment($debug),
        );
    }

    private function makeDiscoveredRoute(string $name): DiscoveredRoute
    {
        return new DiscoveredRoute(
            path: '/__error__',
            methods: ['GET'],
            name: $name,
            requestClass: '',
            responseClass: null,
            handlers: [],
            type: 'http-request',
            transport: null,
            produces: null,
            consumes: null,
            module: '',
        );
    }

    private function makeHtmlRequest(string $uri): Request
    {
        return new Request(
            method: 'GET',
            uri: $uri,
            headers: ['Accept' => 'text/html'],
            query: [],
            post: [],
            server: [],
            cookies: [],
        );
    }

    private function makeEnvironment(bool $debug = false): Environment
    {
        return new Environment(
            appEnv: $debug ? 'dev' : 'prod',
            appDebug: $debug,
            appName: 'Semitexa Test',
            appHost: 'localhost',
            appPort: 8000,
            swoolePort: 9501,
            swooleSsePort: 9503,
            swooleHost: '127.0.0.1',
            swooleWorkerNum: 1,
            swooleMaxRequest: 1000,
            swooleMaxCoroutine: 1000,
            swooleLogFile: 'var/log/swoole.log',
            swooleLogLevel: 1,
            swooleSessionTableSize: 1024,
            swooleSessionMaxBytes: 65535,
            swooleSseWorkerTableSize: 1024,
            swooleSseDeliverTableSize: 1024,
            swooleSsePayloadMaxBytes: 65535,
            corsAllowOrigin: '*',
            corsAllowMethods: 'GET, POST',
            corsAllowHeaders: 'Content-Type',
            corsAllowCredentials: false,
        );
    }
}

final class InMemoryContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $services;

    /**
     * @param array<string, object> $services
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class('Unknown service: ' . $id) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
            };
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
