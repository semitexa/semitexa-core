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
