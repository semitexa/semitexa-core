<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Cursor\CollectionCursor;
use Semitexa\Core\Resource\Cursor\CollectionCursorCodec;
use Semitexa\Core\Resource\Exception\InvalidCursorException;

/**
 * Phase 6l: cursor codec is the only place where opaque tokens are
 * minted and validated. Every rejection path must surface as
 * `InvalidCursorException` (HTTP 400); successful decode must
 * verify the request's sort + filter signatures match.
 */
final class Phase6lCursorCodecTest extends TestCase
{
    private function codec(): CollectionCursorCodec
    {
        return new CollectionCursorCodec();
    }

    private function sample(): CollectionCursor
    {
        return new CollectionCursor(
            version:         CollectionCursor::CURRENT_VERSION,
            sortSignature:   'name',
            filterSignature: 'id:in:1,2,3',
            lastSortKey:     ['Acme'],
            lastId:          '1',
        );
    }

    // ----- Encoding determinism --------------------------------------

    #[Test]
    public function encode_is_deterministic(): void
    {
        $codec = $this->codec();
        self::assertSame(
            $codec->encode($this->sample()),
            $codec->encode($this->sample()),
        );
    }

    #[Test]
    public function encode_uses_base64url_alphabet(): void
    {
        $token = $this->codec()->encode($this->sample());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        // Phase 6l strips trailing `=` padding for cleanliness in URLs.
        self::assertStringNotContainsString('=', $token);
    }

    // ----- Round-trip ------------------------------------------------

    #[Test]
    public function decode_round_trips_a_freshly_encoded_token(): void
    {
        $codec    = $this->codec();
        $original = $this->sample();
        $token    = $codec->encode($original);

        $decoded = $codec->decode(
            $token,
            $original->sortSignature,
            $original->filterSignature,
        );

        self::assertEquals($original, $decoded);
    }

    // ----- Malformed inputs ------------------------------------------

    #[Test]
    public function rejects_empty_token(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/cursor token is empty/');
        $this->codec()->decode('', 'sig', 'fsig');
    }

    #[Test]
    public function rejects_invalid_base64(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/not valid base64url/');
        // `!` is not in the base64url alphabet.
        $this->codec()->decode('not!a!base64', 'sig', 'fsig');
    }

    #[Test]
    public function rejects_non_json_body(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        $token = CollectionCursorCodec::base64UrlEncode('this is not json');
        $this->codec()->decode($token, 'sig', 'fsig');
    }

    #[Test]
    public function rejects_non_object_json(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/cursor body must be a JSON object/');
        $token = CollectionCursorCodec::base64UrlEncode('"a string"');
        $this->codec()->decode($token, 'sig', 'fsig');
    }

    // ----- Schema validation -----------------------------------------

    #[Test]
    public function rejects_missing_required_field(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/missing field "v"/');
        $token = CollectionCursorCodec::base64UrlEncode(json_encode(['s' => '', 'f' => '', 'k' => [], 'i' => '1'], JSON_THROW_ON_ERROR));
        $this->codec()->decode($token, '', '');
    }

    #[Test]
    public function rejects_non_integer_version(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/field "v" must be an integer/');
        $token = CollectionCursorCodec::base64UrlEncode(
            json_encode(['v' => '1', 's' => '', 'f' => '', 'k' => [], 'i' => '1'], JSON_THROW_ON_ERROR),
        );
        $this->codec()->decode($token, '', '');
    }

    #[Test]
    public function rejects_unsupported_version(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/version 99 is not supported/');
        $token = CollectionCursorCodec::base64UrlEncode(
            json_encode(['v' => 99, 's' => '', 'f' => '', 'k' => [], 'i' => '1'], JSON_THROW_ON_ERROR),
        );
        $this->codec()->decode($token, '', '');
    }

    #[Test]
    public function rejects_non_string_sort_signature(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/field "s" must be a string/');
        $token = CollectionCursorCodec::base64UrlEncode(
            json_encode(['v' => 1, 's' => 0, 'f' => '', 'k' => [], 'i' => '1'], JSON_THROW_ON_ERROR),
        );
        $this->codec()->decode($token, '', '');
    }

    #[Test]
    public function rejects_non_array_last_sort_key(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/field "k" must be a JSON array/');
        $token = CollectionCursorCodec::base64UrlEncode(
            json_encode(['v' => 1, 's' => '', 'f' => '', 'k' => 'oops', 'i' => '1'], JSON_THROW_ON_ERROR),
        );
        $this->codec()->decode($token, '', '');
    }

    #[Test]
    public function rejects_non_string_last_sort_key_entry(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/field "k\[1\]" must be a string/');
        $token = CollectionCursorCodec::base64UrlEncode(
            json_encode(['v' => 1, 's' => '', 'f' => '', 'k' => ['ok', 5], 'i' => '1'], JSON_THROW_ON_ERROR),
        );
        $this->codec()->decode($token, '', '');
    }

    #[Test]
    public function rejects_empty_last_id(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/field "i" must be a non-empty string/');
        $token = CollectionCursorCodec::base64UrlEncode(
            json_encode(['v' => 1, 's' => '', 'f' => '', 'k' => [], 'i' => ''], JSON_THROW_ON_ERROR),
        );
        $this->codec()->decode($token, '', '');
    }

    // ----- Context binding -------------------------------------------

    #[Test]
    public function rejects_sort_signature_mismatch(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/different \?sort= context/');
        $token = $this->codec()->encode($this->sample());
        // Original sort signature is "name"; replay against "-name".
        $this->codec()->decode($token, '-name', $this->sample()->filterSignature);
    }

    #[Test]
    public function rejects_filter_signature_mismatch(): void
    {
        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessageMatches('/different \?filter= context/');
        $token = $this->codec()->encode($this->sample());
        $this->codec()->decode($token, $this->sample()->sortSignature, 'name:eq:Acme');
    }

    #[Test]
    public function exception_carries_status_400(): void
    {
        try {
            $this->codec()->decode('', 'sig', 'fsig');
            self::fail('Expected InvalidCursorException.');
        } catch (InvalidCursorException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertNotSame('', $e->reason);
        }
    }

    // ----- Stable signature usage ------------------------------------

    #[Test]
    public function encoded_payload_records_provided_signatures_verbatim(): void
    {
        $cursor = new CollectionCursor(
            version:         CollectionCursor::CURRENT_VERSION,
            sortSignature:   '-name,id',
            filterSignature: 'id:in:1,2;name:contains:acme',
            lastSortKey:     ['AcmeCorp', '5'],
            lastId:          '5',
        );
        $token   = $this->codec()->encode($cursor);
        $decoded = $this->codec()->decode($token, '-name,id', 'id:in:1,2;name:contains:acme');
        self::assertSame('-name,id', $decoded->sortSignature);
        self::assertSame('id:in:1,2;name:contains:acme', $decoded->filterSignature);
        self::assertSame(['AcmeCorp', '5'], $decoded->lastSortKey);
        self::assertSame('5', $decoded->lastId);
    }
}
