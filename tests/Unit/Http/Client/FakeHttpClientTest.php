<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\FakeHttpClient;
use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\HttpResponse;
use Semitexa\Core\Http\Client\HttpTransportKind;
use Semitexa\Core\Http\Exception\HttpTransportException;

final class FakeHttpClientTest extends TestCase
{
    #[Test]
    public function routes_answer_first_then_the_queue_in_order_and_everything_is_recorded(): void
    {
        $http = (new FakeHttpClient())
            ->when('POST', '#/v1/messages$#', HttpResponse::make(200, '{"ok":true}'))
            ->queue(HttpResponse::make(201), HttpResponse::make(202));

        self::assertSame(['ok' => true], $http->postJson('https://api.test/v1/messages', ['m' => 1], ['x-api-key' => 'k'])->json());
        self::assertSame(201, $http->get('https://api.test/a')->status);
        self::assertSame(200, $http->postJson('https://api.test/v1/messages', [])->status, 'a route answers every time');
        self::assertSame(202, $http->get('https://api.test/b')->status);

        $http->assertSentCount(4);
        $http->assertSent(static fn (HttpRequest $r): bool => $r->header('X-Api-Key') === 'k' && $r->body === '{"m":1}');
        self::assertSame(['POST', 'GET', 'POST', 'GET'], array_map(static fn (HttpRequest $r): string => $r->method, $http->recorded()));
    }

    #[Test]
    public function an_unanswered_request_fails_instead_of_inventing_a_response(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GET https://api.test/x');

        (new FakeHttpClient())->get('https://api.test/x?token=secret');
    }

    #[Test]
    public function a_queued_transport_failure_is_thrown_and_a_stream_gets_its_body(): void
    {
        $http = (new FakeHttpClient())->queue(
            new HttpTransportException(HttpTransportKind::Timeout, 'GET', 'https://api.test/', 'timed out'),
            HttpResponse::make(200, 'data: 1'),
        );

        try {
            $http->get('https://api.test/');
            self::fail('the queued failure was not thrown');
        } catch (HttpTransportException $e) {
            self::assertSame(HttpTransportKind::Timeout, $e->kind);
        }

        $chunks = [];
        $http->get('https://api.test/stream', [], new HttpCallOptions(onChunk: static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }));
        self::assertSame(['data: 1'], $chunks);
    }

    #[Test]
    public function assert_sent_names_what_was_sent_when_nothing_matched(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));
        $http->get('https://api.test/only');

        try {
            $http->assertSent(static fn (HttpRequest $r): bool => $r->method === 'DELETE');
            self::fail('assertSent passed');
        } catch (\LogicException $e) {
            self::assertStringContainsString('GET https://api.test/only', $e->getMessage());
        }
    }
}
