<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\HealthCheckHandler;
use Semitexa\Core\Server\RawResponse;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

/**
 * Responses written straight to Swoole bypass the emitter, so they have to
 * drop a HEAD body themselves — Swoole sends whatever end() is given, and a
 * keep-alive client reads it as the start of the next response.
 */
final class RawResponseHeadTest extends TestCase
{
    #[Test]
    public function health_answers_head_with_status_and_headers_but_no_body(): void
    {
        $transport = $this->transportSpy();

        self::assertTrue((new HealthCheckHandler())->handle($this->request('HEAD', '/health'), $transport));

        self::assertSame(200, $transport->statusCode);
        self::assertSame('application/json', $transport->headersSet['Content-Type'] ?? null);
        self::assertSame('', $transport->body);
    }

    #[Test]
    public function health_answers_get_with_its_body(): void
    {
        $transport = $this->transportSpy();

        (new HealthCheckHandler())->handle($this->request('GET', '/health'), $transport);

        self::assertIsString($transport->body);
        self::assertStringContainsString('"status":"ok"', $transport->body);
    }

    #[Test]
    public function the_method_match_is_case_insensitive_and_tolerates_a_missing_method(): void
    {
        self::assertTrue(RawResponse::isHead($this->request('head', '/')));
        self::assertFalse(RawResponse::isHead($this->request('GET', '/')));
        self::assertFalse(RawResponse::isHead(new SwooleRequest()));
    }

    private function request(string $method, string $uri): SwooleRequest
    {
        $request = new SwooleRequest();
        $request->server = ['request_method' => $method, 'request_uri' => $uri];

        return $request;
    }

    /**
     * @return SwooleResponse&object{headersSet: array<string, mixed>, statusCode: ?int, body: mixed}
     */
    private function transportSpy(): SwooleResponse
    {
        return new class extends SwooleResponse {
            /** @var array<string, mixed> */
            public array $headersSet = [];
            public ?int $statusCode = null;
            public mixed $body = null;

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
                $this->body = $content;

                return true;
            }
        };
    }
}
