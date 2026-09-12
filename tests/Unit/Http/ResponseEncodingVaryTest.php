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
    private function headersFor(array $headers = []): array
    {
        $method = new ReflectionMethod(SwooleResponseEmitter::class, 'withEncodingVary');

        return $method->invoke(null, $headers);
    }

    #[Test]
    public function a_response_declares_the_dependency(): void
    {
        $headers = $this->headersFor(['Content-Type' => 'text/html']);

        self::assertSame('Accept-Encoding', $headers['Vary'] ?? null);
        self::assertSame('text/html', $headers['Content-Type'], 'other headers are left alone');
    }

    /**
     * Declared whatever the body weighs, because the threshold is not ours.
     *
     * The first version tested the body against 20 bytes — Swoole's default
     * `compression_min_length`, copied into this codebase, where it would have
     * to keep agreeing with a value it does not own. An application that lowers
     * that setting would get compressed responses with no Vary, which is the
     * exact cache-poisoning the header prevents. Over-declaring on a body too
     * short to compress splits a cache key nobody uses; under-declaring hands a
     * client bytes it cannot read. Only one of those is worth avoiding.
     */
    #[Test]
    public function the_declaration_does_not_depend_on_a_threshold_we_do_not_own(): void
    {
        self::assertSame('Accept-Encoding', $this->headersFor()['Vary'] ?? null);
        self::assertSame('Accept-Encoding', $this->headersFor(['Content-Length' => '3'])['Vary'] ?? null);
    }

    /**
     * A response that already varies on something still varies on it. Replacing
     * that list would make a per-user response look shareable, which is a
     * privacy failure rather than a caching one.
     */
    #[Test]
    public function an_existing_vary_is_extended_rather_than_replaced(): void
    {
        $headers = $this->headersFor(['Vary' => 'Cookie']);
        self::assertSame('Cookie, Accept-Encoding', $headers['Vary']);

        $listed = $this->headersFor(['Vary' => 'Cookie, Accept-Language']);
        self::assertSame('Cookie, Accept-Language, Accept-Encoding', $listed['Vary']);
    }

    /** Whatever case or spacing it was written in, it is not added twice. */
    #[Test]
    public function a_dependency_already_declared_is_not_repeated(): void
    {
        foreach (['Accept-Encoding', 'accept-encoding', 'Cookie,  Accept-Encoding'] as $existing) {
            $headers = $this->headersFor(['Vary' => $existing]);
            self::assertSame($existing, $headers['Vary'], $existing);
        }

        $lowercase = $this->headersFor(['vary' => 'accept-encoding']);
        self::assertSame('accept-encoding', $lowercase['vary']);
        self::assertArrayNotHasKey('Vary', $lowercase, 'the header name is case-insensitive; do not add a second one');
    }

    /**
     * `Vary: *` already says the response is effectively uncacheable, and
     * narrowing it into a list claims the opposite. Trimmed, because a
     * hand-written header may carry spaces and « * » is still a wildcard.
     */
    #[Test]
    public function a_wildcard_vary_is_left_exactly_as_it_is(): void
    {
        self::assertSame('*', $this->headersFor(['Vary' => '*'])['Vary']);
        self::assertSame(' * ', $this->headersFor(['Vary' => ' * '])['Vary'], 'spacing does not make it a list');
    }

    /** A list-valued header survives the round trip as a single joined value. */
    #[Test]
    public function an_array_valued_vary_is_joined_and_extended(): void
    {
        $headers = $this->headersFor(['Vary' => ['Cookie', 'Accept-Language']]);

        self::assertSame('Cookie, Accept-Language, Accept-Encoding', $headers['Vary']);
    }
}
