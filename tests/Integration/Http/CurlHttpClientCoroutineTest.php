<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\HttpTransportKind;
use Semitexa\Core\Http\Client\RetryPolicy;
use Semitexa\Core\Http\Exception\HttpTransportException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Runtime;

/**
 * CurlHttpClient as it runs in a worker: curl hooked by Swoole (SWOOLE_HOOK_ALL),
 * against a real HTTP server in the same process. The hook refuses curl
 * options it does not implement, so a CurlException here means an option the
 * client must not use.
 */
final class CurlHttpClientCoroutineTest extends TestCase
{
    #[Test]
    public function the_client_works_under_the_curl_hook(): void
    {
        $results = [];
        $flaky = 0;
        $limited = 0;
        $sleeps = [];
        $flakyStream = 0;

        // 50 MB of zeros, gzip-encoded in pieces: ~50 KB on the wire, a bomb
        // for a client that inflates before it counts.
        $deflate = deflate_init(ZLIB_ENCODING_GZIP);
        $bomb = '';
        for ($i = 0; $i < 50; $i++) {
            $bomb .= deflate_add($deflate, str_repeat("\0", 1_000_000), ZLIB_NO_FLUSH);
        }
        $bomb .= deflate_add($deflate, '', ZLIB_FINISH);

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            Coroutine\run(function () use (&$results, &$flaky, &$limited, &$sleeps, &$flakyStream, $bomb): void {
                $server = new Server('127.0.0.1', 0);
                $server->handle('/', function (Request $request, Response $response) use (&$flaky, &$limited, &$flakyStream, $bomb, &$server): void {
                    $path = $request->server['request_uri'] ?? '/';
                    match ($path) {
                        '/echo' => $response->end((string) json_encode([
                            'method' => $request->server['request_method'],
                            'auth' => $request->header['authorization'] ?? null,
                            'body' => $request->rawContent(),
                            'length' => $request->header['content-length'] ?? null,
                        ])),
                        '/bomb' => (static function () use ($response, $bomb): void {
                            $response->header('Content-Encoding', 'gzip');
                            $response->end($bomb);
                        })(),
                        '/huge' => (static function () use ($response): void {
                            for ($i = 0; $i < 20; $i++) {
                                $response->write(str_repeat('x', 1_000_000));
                            }
                            $response->end();
                        })(),
                        '/away' => (static function () use ($response, &$server): void {
                            $response->status(302);
                            $response->header('Location', 'http://localhost:' . $server->port . '/echo');
                            $response->end();
                        })(),
                        '/home' => (static function () use ($response): void {
                            $response->status(302);
                            $response->header('Location', '/echo');
                            $response->end();
                        })(),
                        '/flaky-stream' => (static function () use ($response, &$flakyStream): void {
                            $response->status(++$flakyStream < 2 ? 503 : 200);
                            $response->end($flakyStream < 2 ? 'error page' : 'data: 1');
                        })(),
                        '/slow' => (static function () use ($response): void {
                            Coroutine::sleep(0.4);
                            $response->end('slow');
                        })(),
                        '/flaky' => (static function () use ($response, &$flaky): void {
                            $response->status(++$flaky < 3 ? 503 : 200);
                            $response->end('attempt ' . $flaky);
                        })(),
                        '/limited' => (static function () use ($response, &$limited): void {
                            if (++$limited === 1) {
                                $response->status(429);
                                $response->header('Retry-After', '1');
                            }
                            $response->end('limited ' . $limited);
                        })(),
                        '/big' => $response->end(str_repeat('x', 300_000)),
                        '/head' => (static function () use ($response): void {
                            $response->header('X-Probe', 'yes');
                            $response->end(str_repeat('y', 5000));
                        })(),
                        default => (static function () use ($response): void {
                            $response->status(404);
                            $response->end('nope');
                        })(),
                    };
                });
                Coroutine::create(static fn () => $server->start());
                $base = 'http://127.0.0.1:' . $server->port;

                $client = CurlHttpClient::withTiming(
                    static function (int $ms) use (&$sleeps): void {
                        $sleeps[] = $ms;
                        usleep($ms * 1000);
                    },
                    static fn (int $min, int $max): int => min($max, 50),
                );

                $results['echo'] = $client->postJson($base . '/echo', ['a' => 'б'], ['Authorization' => 'Bearer t']);
                $results['missing'] = $client->get($base . '/missing');

                $started = microtime(true);
                $wg = new Coroutine\WaitGroup();
                for ($i = 0; $i < 3; $i++) {
                    $wg->add();
                    Coroutine::create(static function () use ($client, $base, $wg, &$results, $i): void {
                        $results["slow{$i}"] = $client->get($base . '/slow');
                        $wg->done();
                    });
                }
                $wg->wait();
                $results['concurrentSeconds'] = microtime(true) - $started;

                $results['flaky'] = $client->get($base . '/flaky', [], new HttpCallOptions(retry: RetryPolicy::idempotent(3)));
                $results['limited'] = $client->get($base . '/limited', [], new HttpCallOptions(retry: RetryPolicy::idempotent(2)));
                $results['postNotRetried'] = $client->send(HttpRequest::make('POST', $base . '/flaky'), new HttpCallOptions(retry: RetryPolicy::idempotent(3)));
                $results['head'] = $client->send(HttpRequest::make('HEAD', $base . '/head'), new HttpCallOptions(timeout: 2.0));
                try {
                    $client->get($base . '/big', [], new HttpCallOptions(maxResponseBytes: 100_000));
                } catch (HttpTransportException $e) {
                    $results['big'] = $e;
                }
                try {
                    $client->get('http://127.0.0.1:1/', [], new HttpCallOptions(connectTimeout: 1.0, retry: RetryPolicy::idempotent(2)));
                } catch (HttpTransportException $e) {
                    $results['refused'] = $e;
                }

                memory_reset_peak_usage();
                $base0 = memory_get_usage();
                try {
                    $client->get($base . '/bomb', [], new HttpCallOptions(maxResponseBytes: 1_000_000));
                } catch (HttpTransportException $e) {
                    $results['bomb'] = $e;
                }
                $results['bombPeakMb'] = (memory_get_peak_usage() - $base0) / 1e6;

                memory_reset_peak_usage();
                $base0 = memory_get_usage();
                try {
                    $client->get($base . '/huge', [], new HttpCallOptions(maxResponseBytes: 2_000_000));
                } catch (HttpTransportException $e) {
                    $results['huge'] = $e;
                }
                $results['hugePeakMb'] = (memory_get_peak_usage() - $base0) / 1e6;

                $follow = new HttpCallOptions(followRedirects: true);
                $results['away'] = $client->get($base . '/away', ['Authorization' => 'Bearer secret', 'Accept' => 'application/json'], $follow);
                $results['home'] = $client->get($base . '/home', ['Authorization' => 'Bearer secret'], $follow);
                $results['emptyPost'] = $client->send(HttpRequest::make('POST', $base . '/echo'));

                $chunks = '';
                $results['stream'] = $client->get($base . '/flaky-stream', [], new HttpCallOptions(
                    retry: RetryPolicy::idempotent(2),
                    onChunk: static function (string $chunk) use (&$chunks): void {
                        $chunks .= $chunk;
                    },
                ));
                $results['streamChunks'] = $chunks;

                $server->shutdown();
            });
        } finally {
            Runtime::enableCoroutine(0);
        }

        $echo = $results['echo']->json();
        self::assertSame(['method' => 'POST', 'auth' => 'Bearer t', 'body' => '{"a":"б"}', 'length' => '10'], $echo);
        self::assertSame(404, $results['missing']->status, 'a 404 is returned, not thrown');

        self::assertLessThan(1.0, $results['concurrentSeconds'], 'three 0.4 s calls overlap: the hook yields instead of blocking');

        self::assertSame(200, $results['flaky']->status);
        self::assertSame(3, $results['flaky']->attempts);
        self::assertSame(200, $results['limited']->status);
        self::assertContains(1000, $sleeps, 'Retry-After: 1 waited one second');
        self::assertSame(1, $results['postNotRetried']->attempts, 'a POST is not resent');

        self::assertSame(200, $results['head']->status);
        self::assertSame('yes', $results['head']->header('x-probe'));
        self::assertLessThan(1500.0, $results['head']->durationMs, 'HEAD does not wait for a body');

        self::assertSame(HttpTransportKind::TooLarge, $results['big']->kind);

        // review: the cap was checked after the body (and its inflation) was in memory.
        self::assertSame(HttpTransportKind::TooLarge, $results['bomb']->kind);
        self::assertLessThan(10.0, $results['bombPeakMb'], 'a gzip bomb stops at the cap, not in memory');
        self::assertSame(HttpTransportKind::TooLarge, $results['huge']->kind);
        self::assertLessThan(10.0, $results['hugePeakMb'], '20 MB against a 2 MB cap stays bounded');

        // review: a redirect to another origin carried the Authorization header there.
        self::assertSame(200, $results['away']->status);
        self::assertNull($results['away']->json()['auth'], 'credentials stay with their origin');
        self::assertSame('Bearer secret', $results['home']->json()['auth'], 'a same-origin hop keeps them');
        self::assertSame('0', $results['emptyPost']->json()['length'], 'an empty POST says Content-Length: 0');

        // review: a stream handed the caller the 503 body, then the retried one.
        self::assertSame('data: 1', $results['streamChunks']);
        self::assertSame(2, $results['stream']->attempts);
        self::assertSame(HttpTransportKind::Connect, $results['refused']->kind);
        self::assertSame(2, $results['refused']->attempts);
    }
}
