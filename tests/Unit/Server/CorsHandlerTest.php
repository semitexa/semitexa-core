<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\CorsHandler;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

/**
 * Pins the preflight/actual-OPTIONS distinction (One Way regression).
 *
 * Browsers attach an `Origin` header to EVERY non-GET fetch — including a
 * same-origin explicit `fetch(url, {method: 'OPTIONS'})`, which is the route
 * contract channel the metadata-driven grid runtime boots from. Only a real
 * CORS preflight carries `Access-Control-Request-Method`; the handler must
 * short-circuit exactly that and let explicit OPTIONS fall through to routing
 * (OptionsMetadataHandler).
 */
final class CorsHandlerTest extends TestCase
{
    #[Test]
    public function preflight_options_with_request_method_marker_is_short_circuited(): void
    {
        $response = $this->makeResponseSpy();
        $handled = $this->handler()->handle(
            $this->makeRequest('OPTIONS', [
                'origin' => 'http://localhost:9502',
                'access-control-request-method' => 'POST',
            ]),
            $response,
        );

        self::assertTrue($handled);
        self::assertTrue($response->ended);
        self::assertSame(204, $response->statusCode);
        self::assertArrayHasKey('Access-Control-Allow-Methods', $response->headersSet);
    }

    #[Test]
    public function explicit_options_without_preflight_marker_falls_through_to_routing(): void
    {
        $response = $this->makeResponseSpy();
        $handled = $this->handler()->handle(
            $this->makeRequest('OPTIONS', ['origin' => 'http://localhost:9502']),
            $response,
        );

        self::assertFalse($handled, 'explicit OPTIONS must reach OptionsMetadataHandler');
        self::assertFalse($response->ended);
        self::assertNull($response->statusCode);
        // The CORS allow-origin decoration still applies to the real response.
        self::assertArrayHasKey('Access-Control-Allow-Origin', $response->headersSet);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $response->headersSet);
    }

    #[Test]
    public function non_options_request_with_origin_only_gets_decorated(): void
    {
        $response = $this->makeResponseSpy();
        $handled = $this->handler()->handle(
            $this->makeRequest('GET', ['origin' => 'http://localhost:9502']),
            $response,
        );

        self::assertFalse($handled);
        self::assertFalse($response->ended);
        self::assertArrayHasKey('Access-Control-Allow-Origin', $response->headersSet);
    }

    #[Test]
    public function request_without_origin_is_untouched(): void
    {
        $response = $this->makeResponseSpy();
        $handled = $this->handler()->handle(
            $this->makeRequest('OPTIONS', ['access-control-request-method' => 'POST']),
            $response,
        );

        self::assertFalse($handled);
        self::assertFalse($response->ended);
        self::assertSame([], $response->headersSet);
    }

    private function handler(): CorsHandler
    {
        return new CorsHandler($this->makeEnvironment());
    }

    private function makeRequest(string $method, array $headers): SwooleRequest
    {
        $request = new class extends SwooleRequest {};
        $request->header = $headers;
        $request->server = ['request_method' => $method];

        return $request;
    }

    /**
     * @return SwooleResponse&object{headersSet: array<string, mixed>, statusCode: ?int, ended: bool}
     */
    private function makeResponseSpy(): SwooleResponse
    {
        return new class extends SwooleResponse {
            /** @var array<string, mixed> */
            public array $headersSet = [];
            public ?int $statusCode = null;
            public bool $ended = false;

            public function header($key, $value, $format = true): bool
            {
                $this->headersSet[$key] = $value;

                return true;
            }

            public function status($http_code, $reason = ''): bool
            {
                $this->statusCode = (int) $http_code;

                return true;
            }

            public function end($content = null): bool
            {
                $this->ended = true;

                return true;
            }
        };
    }

    private function makeEnvironment(): Environment
    {
        return new Environment(
            appEnv: 'dev',
            appDebug: true,
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
