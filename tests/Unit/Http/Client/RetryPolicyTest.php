<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\HttpResponse;
use Semitexa\Core\Http\Client\HttpTransportKind;
use Semitexa\Core\Http\Client\RetryPolicy;
use Semitexa\Core\Http\Exception\HttpTransportException;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function nothing_is_retried_unless_asked_and_a_post_only_when_allowed(): void
    {
        self::assertFalse(RetryPolicy::none()->appliesTo('GET'));
        self::assertTrue(RetryPolicy::idempotent(3)->appliesTo('get'));
        self::assertTrue(RetryPolicy::idempotent(3)->appliesTo('PUT'));
        self::assertFalse(RetryPolicy::idempotent(3)->appliesTo('POST'), 'a resent POST can charge twice');
        self::assertTrue(RetryPolicy::idempotent(3)->allowingNonIdempotent()->appliesTo('POST'));
        self::assertFalse(RetryPolicy::idempotent(1)->appliesTo('GET'), 'one attempt is no retry');
    }

    #[Test]
    public function the_statuses_worth_another_try(): void
    {
        $policy = RetryPolicy::idempotent();
        foreach ([429, 500, 502, 503, 504] as $status) {
            self::assertTrue($policy->retriesStatus($status), (string) $status);
        }
        foreach ([200, 400, 401, 404, 409, 501] as $status) {
            self::assertFalse($policy->retriesStatus($status), (string) $status);
        }
    }

    #[Test]
    public function backoff_is_jittered_exponential_capped_and_linear_when_asked(): void
    {
        $ceiling = static fn (int $min, int $max): int => $max;
        $policy = RetryPolicy::idempotent(10, 200, 1000);

        self::assertSame([200, 400, 800, 1000, 1000], array_map(static fn (int $n): int => $policy->delayMs($n, null, $ceiling), [1, 2, 3, 4, 5]));
        self::assertSame(0, $policy->delayMs(3, null, static fn (int $min, int $max): int => $min), 'full jitter starts at zero');
        self::assertSame([500, 1000, 1500], array_map(static fn (int $n): int => RetryPolicy::linear(4)->delayMs($n, null, $ceiling), [1, 2, 3]));
    }

    #[Test]
    public function retry_after_is_honoured_up_to_the_cap(): void
    {
        $policy = RetryPolicy::idempotent(3, 200, 5000);
        $never = static fn (int $min, int $max): int => throw new \LogicException('no jitter with Retry-After');

        self::assertSame(2000, $policy->delayMs(1, RetryPolicy::retryAfterMs('2', 0), $never));
        self::assertSame(5000, $policy->delayMs(1, RetryPolicy::retryAfterMs('120', 0), $never), 'capped');
        self::assertSame(3000, RetryPolicy::retryAfterMs(gmdate('D, d M Y H:i:s', 1_000_003) . ' GMT', 1_000_000));
        self::assertNull(RetryPolicy::retryAfterMs('soon', 0));
        self::assertNull(RetryPolicy::retryAfterMs(null, 0));
    }

    #[Test]
    public function a_server_cannot_make_the_client_sleep_for_days_or_crash_it(): void
    {
        // review: a 23-digit Retry-After became a float (TypeError under
        // strict_types), and linear() had no cap: 999999 s was 11.5 days.
        $never = static fn (int $min, int $max): int => 0;
        self::assertSame(RetryPolicy::MAX_RETRY_AFTER_MS, RetryPolicy::retryAfterMs('99999999999999999999999', 0));
        self::assertSame(30_000, RetryPolicy::linear(3)->delayMs(1, RetryPolicy::retryAfterMs('999999', 0), $never), 'the policy cap, under the global one');
        self::assertNull(RetryPolicy::retryAfterMs('+10 years', 0), 'only an HTTP date, not anything strtotime() takes');
        self::assertSame(30_000, RetryPolicy::linear(1000, 500)->delayMs(999, null, $never), 'linear is capped too');
    }

    #[Test]
    public function a_header_cannot_write_a_header_of_its_own_and_the_method_is_upper_cased(): void
    {
        foreach ([['X-A', "v\r\nX-Injected: 1"], ['X-A', "v\nX: 1"], ['X-A', "v\0"], ['X:A', 'v'], ['X A', 'v'], ['', 'v']] as [$name, $value]) {
            try {
                HttpRequest::make('GET', 'http://x.test/')->withHeader($name, $value);
                self::fail('accepted header ' . json_encode([$name, $value]));
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame('HEAD', (new HttpRequest('head', 'http://x.test/'))->method, 'a lower-case head must still set NOBODY');
        // `$` alone matched before a final newline: "GET\n" passed as a method.
        foreach (["GET\n", "HEAD\n"] as $method) {
            try {
                new HttpRequest($method, 'http://x.test/');
                self::fail('accepted method ' . json_encode($method));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('An HTTP method is letters only.', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new HttpRequest("GET\r\nX: 1", 'http://x.test/');
    }

    /** A 307/308 to another origin replayed the body there: a client_secret, a token. */
    #[Test]
    public function a_cross_origin_redirect_that_would_resend_the_body_is_refused(): void
    {
        $redirected = new \ReflectionMethod(\Semitexa\Core\Http\Client\CurlHttpClient::class, 'redirected');
        $post = HttpRequest::make('POST', 'https://api.a.test/token')->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withBody('client_secret=s');
        $follow = new \Semitexa\Core\Http\Client\HttpCallOptions(followRedirects: true);

        foreach ([307, 308] as $status) {
            try {
                $redirected->invoke(null, $post, $status, 'https://b.test/x', $follow);
                self::fail("followed a {$status} to another origin with the body");
            } catch (HttpTransportException $e) {
                self::assertSame([HttpTransportKind::Protocol, 'a cross-origin redirect would resend the request body'], [$e->kind, $e->detail]);
            }
        }

        $same = $redirected->invoke(null, $post, 307, '/token2', $follow);
        self::assertSame(['POST', 'https://api.a.test/token2', 'client_secret=s'], [$same->method, $same->url, $same->body], 'the same origin keeps it');
        $seeOther = $redirected->invoke(null, $post, 303, 'https://b.test/done', $follow);
        self::assertSame(['GET', 'https://b.test/done', '', []], [$seeOther->method, $seeOther->url, $seeOther->body, $seeOther->headers], 'a 303 drops the body, so it may leave');
    }

    #[Test]
    public function a_transport_failure_names_no_query_string_header_or_body(): void
    {
        $e = new HttpTransportException(HttpTransportKind::Timeout, 'GET', 'https://user:pw@api.example.test:8443/v1/x?key=sk-secret', 'Operation timed out');

        self::assertStringContainsString('https://api.example.test:8443/v1/x', $e->getMessage());
        self::assertStringNotContainsString('sk-secret', $e->getMessage());
        self::assertStringNotContainsString('pw', $e->getMessage());
        self::assertTrue($e->isRetryable());
        self::assertFalse((new HttpTransportException(HttpTransportKind::TooLarge, 'GET', 'http://x.test/', 'big'))->isRetryable());
    }

    #[Test]
    public function headers_replace_regardless_of_case_and_responses_read_them_lower_cased(): void
    {
        $request = HttpRequest::make('post', 'http://x.test/')->withHeader('Content-Type', 'text/plain')->withHeader('content-type', 'application/json');

        self::assertSame('POST', $request->method);
        self::assertSame(['content-type' => ['application/json']], $request->headers);
        self::assertSame('application/json', $request->header('CONTENT-TYPE'));

        $response = HttpResponse::make(201, '{"a":1}', ['Retry-After' => '3']);
        self::assertSame('3', $response->header('retry-after'));
        self::assertTrue($response->isSuccessful());
        self::assertSame(['a' => 1], $response->json());
    }
}
