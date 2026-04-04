<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application;
use Semitexa\Core\Environment;
use Semitexa\Core\Request;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\HttpResponse;

final class SwooleBootstrapTest extends TestCase
{
    #[Test]
    public function bootstrap_fatal_error_returns_html_for_browser_requests(): void
    {
        $response = SwooleBootstrap::buildFatalErrorResponse(
            new \RuntimeException('Boot failed'),
            $this->makeEnvironment(debug: false),
            $this->makeHtmlRequest('/boom'),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type']);
        self::assertStringContainsString('500 Internal Server Error', $response->getContent());
    }

    #[Test]
    public function bootstrap_fatal_error_uses_application_error_route_when_available(): void
    {
        $app = new class extends Application {
            public function __construct()
            {
            }

            public function renderErrorThrowable(\Throwable $throwable, Request $request, ?array $currentRoute = null): ?HttpResponse
            {
                return HttpResponse::html('<h1>custom fatal</h1>', 500);
            }
        };

        $response = SwooleBootstrap::buildFatalErrorResponse(
            new \RuntimeException('Boot failed'),
            $this->makeEnvironment(debug: false),
            $this->makeHtmlRequest('/boom'),
            $app,
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('custom fatal', $response->getContent());
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
