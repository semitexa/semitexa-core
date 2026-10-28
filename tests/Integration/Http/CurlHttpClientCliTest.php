<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\RetryPolicy;
use Symfony\Component\Process\Process;

/**
 * CurlHttpClient as a CLI command runs it: native libcurl, no Swoole hook,
 * against `php -S`. Here a HEAD without NOBODY waited for a body until the
 * timeout, and a gzip body must come back decoded.
 */
final class CurlHttpClientCliTest extends TestCase
{
    private ?Process $server = null;
    private string $dir = '';
    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-http-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/router.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path === '/echo') {
                header('Content-Type: application/json');
                echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'body' => file_get_contents('php://input')]);
            } elseif ($path === '/gzip') {
                header('Content-Encoding: gzip');
                echo gzencode(str_repeat('semitexa ', 100));
            } elseif ($path === '/head') {
                header('Content-Length: 5000');
                header('X-Probe: yes');
                if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
                    echo str_repeat('y', 5000);
                }
            } elseif ($path === '/flaky') {
                $file = __DIR__ . '/flaky.count';
                $n = (int) @file_get_contents($file) + 1;
                file_put_contents($file, (string) $n);
                http_response_code($n < 2 ? 503 : 200);
                echo 'attempt ' . $n;
            } else {
                http_response_code(404);
            }
            PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $this->server = new Process([PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->dir . '/router.php']);
        $this->server->start();
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && @fsockopen('127.0.0.1', $this->port) === false) {
            usleep(50_000);
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    #[Test]
    public function trailers_after_a_chunked_body_do_not_replace_the_headers(): void
    {
        // review: on libcurl, trailers reach HEADERFUNCTION after a blank line
        // and were read as a new response: only X-T was left.
        file_put_contents($this->dir . '/raw.php', <<<'PHP'
            <?php
            $server = stream_socket_server('tcp://127.0.0.1:' . $argv[1]);
            // The readiness probe connects and sends nothing: skip it.
            while (($conn = stream_socket_accept($server, 10)) !== false && trim((string) fread($conn, 8192)) === '') {
                fclose($conn);
            }
            fwrite($conn, "HTTP/1.1 200 OK\r\nX-Real: 1\r\nTransfer-Encoding: chunked\r\nTrailer: X-T\r\nConnection: close\r\n\r\n5\r\nhello\r\n0\r\nX-T: 1\r\n\r\n");
            fclose($conn);
            PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $raw = new Process([PHP_BINARY, $this->dir . '/raw.php', (string) $port]);
        $raw->start();
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && @fsockopen('127.0.0.1', $port) === false) {
            usleep(50_000);
        }

        try {
            $response = (new CurlHttpClient())->get('http://127.0.0.1:' . $port . '/');
        } finally {
            $raw->stop(1);
        }

        self::assertSame('hello', $response->body);
        self::assertSame('1', $response->header('X-Real'));
        self::assertSame('1', $response->header('X-T'));
    }

    #[Test]
    public function the_client_works_on_native_curl(): void
    {
        $client = CurlHttpClient::withTiming(static fn (int $ms): null => null, static fn (int $min, int $max): int => 0);
        $base = 'http://127.0.0.1:' . $this->port;

        self::assertSame(['method' => 'PUT', 'body' => 'x=1'], $client->send(HttpRequest::make('PUT', $base . '/echo')->withBody('x=1'))->json());
        self::assertSame(str_repeat('semitexa ', 100), $client->get($base . '/gzip')->body, 'a gzip body arrives decoded');

        $head = $client->send(HttpRequest::make('HEAD', $base . '/head'), new HttpCallOptions(timeout: 3.0));
        self::assertSame(200, $head->status);
        self::assertSame('yes', $head->header('X-Probe'));
        self::assertLessThan(2000.0, $head->durationMs, 'HEAD does not wait for the announced 5000 bytes');

        $flaky = $client->get($base . '/flaky', [], new HttpCallOptions(retry: RetryPolicy::idempotent(3)));
        self::assertSame([200, 2], [$flaky->status, $flaky->attempts]);
    }
}
