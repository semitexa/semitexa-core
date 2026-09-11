<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Core\Http\SwooleResponseEmitter;

/**
 * A dynamic response that Swoole may compress says so.
 *
 * Swoole compresses whatever it `end()`s once the client accepts an encoding
 * and the body clears `compression_min_length`, and it emits no `Vary` of its
 * own — measured on a live server, the gzip answer carried `Content-Encoding`
 * and nothing else. Without the header a shared cache may keep whichever
 * representation it happened to see first under the bare URL and serve it to
 * everyone behind it; in the bad direction that is a gzip body handed to the
 * one client that said it could not read one.
 *
 * The decision is taken from the RESPONSE, never from the request in hand:
 * `Vary` describes what a resource depends on, so a value that flipped with
 * the caller's own Accept-Encoding would describe a different resource to each
 * caller.
 */
final class ResponseEncodingVaryTest extends TestCase
{
    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function headersFor(string $content, array $headers = []): array
    {
        $method = new ReflectionMethod(SwooleResponseEmitter::class, 'withEncodingVary');

        return $method->invoke(null, $headers, $content);
    }

    #[Test]
    public function a_body_that_can_be_compressed_declares_the_dependency(): void
    {
        $headers = $this->headersFor(str_repeat('a', 4096), ['Content-Type' => 'text/html']);

        self::assertSame('Accept-Encoding', $headers['Vary'] ?? null);
        self::assertSame('text/html', $headers['Content-Type'], 'other headers are left alone');
    }

    /**
     * Below Swoole's floor nothing is compressed, so there is only one
     * representation and claiming otherwise splits a cache key for nothing.
     */
    #[Test]
    public function a_body_too_short_to_compress_declares_nothing(): void
    {
        self::assertArrayNotHasKey('Vary', $this->headersFor('tiny'));
        self::assertArrayNotHasKey('Vary', $this->headersFor(str_repeat('x', 19)));
        self::assertArrayHasKey('Vary', $this->headersFor(str_repeat('x', 20)), 'exactly at the floor it can compress');
    }

    /**
     * A response that already varies on something still varies on it. Replacing
     * that list would make a per-user response look shareable, which is a
     * privacy failure rather than a caching one.
     */
    #[Test]
    public function an_existing_vary_is_extended_rather_than_replaced(): void
    {
        $headers = $this->headersFor(str_repeat('a', 4096), ['Vary' => 'Cookie']);
        self::assertSame('Cookie, Accept-Encoding', $headers['Vary']);

        $listed = $this->headersFor(str_repeat('a', 4096), ['Vary' => 'Cookie, Accept-Language']);
        self::assertSame('Cookie, Accept-Language, Accept-Encoding', $listed['Vary']);
    }

    /** Whatever case or spacing it was written in, it is not added twice. */
    #[Test]
    public function a_dependency_already_declared_is_not_repeated(): void
    {
        foreach (['Accept-Encoding', 'accept-encoding', 'Cookie,  Accept-Encoding'] as $existing) {
            $headers = $this->headersFor(str_repeat('a', 4096), ['Vary' => $existing]);
            self::assertSame($existing, $headers['Vary'], $existing);
        }

        $lowercase = $this->headersFor(str_repeat('a', 4096), ['vary' => 'accept-encoding']);
        self::assertSame('accept-encoding', $lowercase['vary']);
        self::assertArrayNotHasKey('Vary', $lowercase, 'the header name is case-insensitive; do not add a second one');
    }

    /** `Vary: *` already says the response is uncacheable; narrowing it would be a lie. */
    #[Test]
    public function a_wildcard_vary_is_left_exactly_as_it_is(): void
    {
        $headers = $this->headersFor(str_repeat('a', 4096), ['Vary' => '*']);

        self::assertSame('*', $headers['Vary']);
    }

    /** A list-valued header survives the round trip as a single joined value. */
    #[Test]
    public function an_array_valued_vary_is_joined_and_extended(): void
    {
        $headers = $this->headersFor(str_repeat('a', 4096), ['Vary' => ['Cookie', 'Accept-Language']]);

        self::assertSame('Cookie, Accept-Language, Accept-Encoding', $headers['Vary']);
    }
}
