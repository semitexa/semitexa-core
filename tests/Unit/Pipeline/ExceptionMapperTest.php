<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Environment;
use Semitexa\Core\Error\ErrorPageContext;
use Semitexa\Core\Error\ErrorPageContextStore;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Pipeline\Exception\AuthenticationRequiredException;
use Semitexa\Core\Exception\PayloadValidationException;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Pipeline\ExceptionMapper;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

final class ExceptionMapperTest extends TestCase
{
    #[Test]
    public function unknown_exceptions_keep_json_response_for_json_clients(): void
    {
        $dispatcher = new ErrorRouteDispatcher(
            routeRegistry: new RouteRegistry(),
            requestScopedContainer: new RequestScopedContainer(new ExceptionMapperTestContainer()),
            container: new ExceptionMapperTestContainer(),
            authBootstrapper: null,
            environment: $this->makeEnvironment(),
            routeExecutor: function (): HttpResponse {
                throw new \RuntimeException('HTML error route should not execute for JSON requests.');
            },
        );

        $mapper = (new ExceptionMapper())->withErrorRouteDispatcher($dispatcher);
        $request = new Request('GET', '/broken', ['Accept' => 'application/json'], [], [], [], []);

        $response = $mapper->map(
            new \RuntimeException('Boom'),
            $request,
            $this->makeMetadata(['json', 'html']),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaders()['Content-Type']);
        self::assertStringContainsString('Internal Server Error', $response->getContent());
    }

    /**
     * The whole way a guest's refusal travels past the gate: the exception the
     * gate throws (PreHydrationAuthGate, AuthorizationListener), the mapper,
     * the dispatcher, and the error.401 route a module registered by name.
     * Only the route's own handler is stood in for.
     */
    #[Test]
    public function a_guest_refused_on_an_html_page_is_answered_by_the_registered_error_401_route(): void
    {
        $routes = new RouteRegistry();
        $routes->register([
            'path' => '/__error__/401',
            'methods' => ['GET'],
            'name' => ErrorRouteDispatcher::ROUTE_NAME_401,
            'class' => 'App\\SignIn\\SignInPagePayload',
            'type' => 'http-request',
        ]);

        $seen = null;
        $dispatcher = new ErrorRouteDispatcher(
            routeRegistry: $routes,
            requestScopedContainer: new RequestScopedContainer(new ExceptionMapperTestContainer()),
            container: new ExceptionMapperTestContainer(),
            authBootstrapper: null,
            environment: $this->makeEnvironment(),
            routeExecutor: static function (DiscoveredRoute $route) use (&$seen): HttpResponse {
                $seen = ErrorPageContextStore::current();

                return HttpResponse::html('<form data-route="' . $route->name . '">sign in</form>', 200);
            },
        );
        $mapper = (new ExceptionMapper())->withErrorRouteDispatcher($dispatcher);
        $request = new Request('GET', '/account', ['Accept' => 'text/html'], ['tab' => 'keys'], [], ['request_uri' => '/uk/account'], []);

        $response = $mapper->map(
            new AuthenticationRequiredException('Authentication required'),
            $request,
            $this->makeMetadata(['text/html']),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('data-route="error.401"', $response->getContent());
        self::assertInstanceOf(ErrorPageContext::class, $seen);
        self::assertSame('/uk/account?tab=keys', $seen->returnTo);
        self::assertSame('/account', $seen->requestPath);
    }

    /**
     * The core package's own pin of the two validation answers. The app-level
     * suites prove them through real routes (RuntimeValidationPipelineTest,
     * PayloadValidationEnvelopeTest); this keeps core from changing them alone.
     */
    #[Test]
    public function pipeline_validation_answers_422_with_the_flat_errors_body_and_a_domain_one_with_the_envelope(): void
    {
        $request = new Request('POST', '/contact', ['Accept' => 'application/json'], [], [], [], []);
        $errors = ['email' => ['Must be a valid email address.']];

        $pipeline = (new ExceptionMapper())->map(new PayloadValidationException($errors), $request, $this->makeMetadata(['json']));
        self::assertSame(422, $pipeline->getStatusCode());
        self::assertSame(['errors' => $errors], json_decode($pipeline->getContent(), true));

        $domain = (new ExceptionMapper())->map(new ValidationException($errors), $request, $this->makeMetadata(['json']));
        self::assertSame(422, $domain->getStatusCode());
        $body = json_decode($domain->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body, 'a handler stating a domain rule answers with the envelope');
        self::assertArrayNotHasKey('errors', $body);
        self::assertSame('validation', $body['error'], 'an envelope, not a null');
        self::assertSame('Validation failed.', $body['message'] ?? null);
        self::assertSame($errors, $body['context']['errors'] ?? null, 'the field errors travel inside the envelope');
    }

    #[Test]
    public function a_route_without_produces_still_answers_an_explicit_text_request_in_text(): void
    {
        foreach ([
            new Request('GET', '/broken', [], ['_format' => 'txt'], [], [], []),
            new Request('GET', '/broken', ['Accept' => 'text/plain'], [], [], [], []),
        ] as $request) {
            $response = (new ExceptionMapper())->map(new \Semitexa\Core\Exception\NotFoundException('Page', 7), $request, $this->makeMetadata(null));

            self::assertSame(404, $response->getStatusCode());
            self::assertStringStartsWith('text/plain', $response->getHeaders()['Content-Type'] ?? '');
            self::assertSame('Page #7 not found.', $response->getContent());
        }
    }

    #[Test]
    public function an_unknown_exception_answers_in_the_negotiated_format_not_a_refused_json(): void
    {
        $refusesJson = new Request('GET', '/broken', ['Accept' => 'application/json;q=0, */*'], [], [], [], []);
        $response = (new ExceptionMapper())->map(new \RuntimeException('Boom'), $refusesJson, $this->makeMetadata(null));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaders()['Content-Type'] ?? '');
        self::assertStringNotContainsString('Boom', $response->getContent(), 'the internal message stays internal');

        $text = new Request('GET', '/broken', ['Accept' => 'text/plain'], [], [], [], []);
        $response = (new ExceptionMapper())->map(new \RuntimeException('Boom'), $text, $this->makeMetadata(null));
        self::assertSame('An unexpected error occurred.', $response->getContent());
    }

    /**
     * @param list<string>|null $produces
     */
    private function makeMetadata(?array $produces): ResolvedRouteMetadata
    {
        return new ResolvedRouteMetadata(
            path: '/broken',
            name: 'demo.page',
            methods: ['GET'],
            requestClass: 'DemoRequest',
            responseClass: 'DemoResponse',
            produces: $produces,
            consumes: null,
            handlers: [],
            requirements: [],
            extensions: [],
        );
    }

    private function makeEnvironment(): Environment
    {
        return new Environment(
            appEnv: 'prod',
            appDebug: false,
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

final class ExceptionMapperTestContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new class('Unknown service: ' . $id) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
        };
    }

    public function has(string $id): bool
    {
        return false;
    }
}
