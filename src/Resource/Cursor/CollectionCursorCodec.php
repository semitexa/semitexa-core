<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Cursor;

use Semitexa\Core\Resource\Exception\InvalidCursorException;

/**
 * Phase 6l: encode / decode opaque collection cursor tokens.
 *
 * Wire shape:
 *
 *   token = base64url( JSON({
 *     "v": 1,                              // version
 *     "s": "<sort signature>",             // CollectionSortRequest::toQueryString()
 *     "f": "<filter signature>",           // CollectionFilterRequest::toQueryString()
 *     "k": ["<last sort key 1>", …],       // string-coerced last row sort values
 *     "i": "<last id>"                     // universal tie-breaker
 *   }) )
 *
 * The codec is deterministic: the same `CollectionCursor` always
 * encodes to the same token. Keys appear in fixed order; JSON is
 * emitted with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 *
 * The token is **opaque**: clients should not parse it, and the
 * framework rejects any structural deviation from the schema with
 * `InvalidCursorException` (HTTP 400). No HMAC / signing in this
 * baseline — the cursor leaks the sort/filter signatures it was
 * generated for, but the values are public-equivalent (already in
 * the request URL). A signed variant can layer on later if a
 * per-route signing key becomes available.
 *
 * Pure: no DB, ORM, HTTP, Request, renderer, resolver, IriBuilder
 * access.
 */
final class CollectionCursorCodec
{
    public function encode(CollectionCursor $cursor): string
    {
        $payload = [
            'v' => $cursor->version,
            's' => $cursor->sortSignature,
            'f' => $cursor->filterSignature,
            'k' => $cursor->lastSortKey,
            'i' => $cursor->lastId,
        ];
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        return self::base64UrlEncode($json);
    }

    /**
     * Decode and validate the token against the current request's
     * sort and filter signatures. Mismatched context throws HTTP
     * 400 — a cursor generated for `sort=name` cannot be replayed
     * against `sort=-name`.
     */
    public function decode(
        string $rawToken,
        string $expectedSortSignature,
        string $expectedFilterSignature,
    ): CollectionCursor {
        if ($rawToken === '') {
            throw new InvalidCursorException('cursor token is empty');
        }

        $decoded = self::base64UrlDecode($rawToken);
        if ($decoded === null) {
            throw new InvalidCursorException('cursor body is not valid base64url');
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCursorException('cursor body is not valid JSON');
        }

        if (!is_array($payload)) {
            throw new InvalidCursorException('cursor body must be a JSON object');
        }

        foreach (['v', 's', 'f', 'k', 'i'] as $required) {
            if (!array_key_exists($required, $payload)) {
                throw new InvalidCursorException(sprintf('cursor body missing field "%s"', $required));
            }
        }

        if (!is_int($payload['v'])) {
            throw new InvalidCursorException('cursor field "v" must be an integer');
        }
        if ($payload['v'] !== CollectionCursor::CURRENT_VERSION) {
            throw new InvalidCursorException(sprintf(
                'cursor version %d is not supported (expected %d)',
                $payload['v'],
                CollectionCursor::CURRENT_VERSION,
            ));
        }
        if (!is_string($payload['s'])) {
            throw new InvalidCursorException('cursor field "s" must be a string');
        }
        if (!is_string($payload['f'])) {
            throw new InvalidCursorException('cursor field "f" must be a string');
        }
        if (!is_array($payload['k']) || !array_is_list($payload['k'])) {
            throw new InvalidCursorException('cursor field "k" must be a JSON array of strings');
        }
        foreach ($payload['k'] as $i => $entry) {
            if (!is_string($entry)) {
                throw new InvalidCursorException(sprintf(
                    'cursor field "k[%d]" must be a string',
                    $i,
                ));
            }
        }
        if (!is_string($payload['i']) || $payload['i'] === '') {
            throw new InvalidCursorException('cursor field "i" must be a non-empty string');
        }

        if ($payload['s'] !== $expectedSortSignature) {
            throw new InvalidCursorException(
                'cursor was generated for a different ?sort= context — ?cursor= must be replayed with the same sort and filter parameters',
            );
        }
        if ($payload['f'] !== $expectedFilterSignature) {
            throw new InvalidCursorException(
                'cursor was generated for a different ?filter= context — ?cursor= must be replayed with the same sort and filter parameters',
            );
        }

        /** @var list<string> $lastSortKey */
        $lastSortKey = $payload['k'];

        return new CollectionCursor(
            version:         $payload['v'],
            sortSignature:   $payload['s'],
            filterSignature: $payload['f'],
            lastSortKey:     $lastSortKey,
            lastId:          $payload['i'],
        );
    }

    /**
     * RFC 7515 §C base64url, no padding. Identical bytes-in →
     * identical bytes-out, so cursors round-trip deterministically.
     */
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $token): ?string
    {
        $padding = strlen($token) % 4;
        if ($padding > 0) {
            $token .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
