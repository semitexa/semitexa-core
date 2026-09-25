<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\SwooleResponseEmitter;
use Semitexa\Core\HttpResponse;
use Swoole\Http\Response as SwooleResponse;

/**
 * Swoole writes whatever end() is given even for a HEAD request, so the
 * emitter has to withhold the body itself.
 */
final class SwooleResponseEmitterHeadTest extends TestCase
{
    #[Test]
    public function a_head_response_keeps_status_and_headers_but_sends_no_body(): void
    {
        $transport = $this->transportSpy();

        (new SwooleResponseEmitter())->emit(HttpResponse::html('<h1>page</h1>', 200), $transport, withBody: false);

        self::assertSame(200, $transport->statusCode);
        self::assertArrayHasKey('Content-Type', $transport->headersSet);
        self::assertTrue($transport->ended);
        self::assertNull($transport->body);
    }

    #[Test]
    public function a_get_response_sends_its_body(): void
    {
        $transport = $this->transportSpy();

        (new SwooleResponseEmitter())->emit(HttpResponse::html('<h1>page</h1>', 200), $transport);

        self::assertSame('<h1>page</h1>', $transport->body);
    }

    /**
     * @return SwooleResponse&object{headersSet: array<string, mixed>, statusCode: ?int, ended: bool, body: mixed}
     */
    private function transportSpy(): SwooleResponse
    {
        return new class extends SwooleResponse {
            /** @var array<string, mixed> */
            public array $headersSet = [];
            public ?int $statusCode = null;
            public bool $ended = false;
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

            public function rawCookie($name, $value = null, $expires = null, $path = null, $domain = null, $secure = null, $httponly = null, $samesite = null, $priority = null, $partitioned = null): bool
            {
                return true;
            }

            public function end($content = null): bool
            {
                $this->ended = true;
                $this->body = $content;

                return true;
            }
        };
    }
}
