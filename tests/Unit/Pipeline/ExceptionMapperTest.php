<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Environment;
use Semitexa\Core\Error\ErrorRouteDispatcher;
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
