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

        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            Coroutine\run(function () use (&$results, &$flaky, &$limited, &$sleeps): void {
                $server = new Server('127.0.0.1', 0);
                $server->handle('/', function (Request $request, Response $response) use (&$flaky, &$limited): void {
                    $path = $request->server['request_uri'] ?? '/';
                    match ($path) {
                        '/echo' => $response->end((string) json_encode([
                            'method' => $request->server['request_method'],
                            'auth' => $request->header['authorization'] ?? null,
                            'body' => $request->rawContent(),
                        ])),
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

                $server->shutdown();
            });
        } finally {
            Runtime::enableCoroutine(0);
        }

        $echo = $results['echo']->json();
        self::assertSame(['method' => 'POST', 'auth' => 'Bearer t', 'body' => '{"a":"б"}'], $echo);
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
        self::assertSame(HttpTransportKind::Connect, $results['refused']->kind);
        self::assertSame(2, $results['refused']->attempts);
    }
}
