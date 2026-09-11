<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\HttpResponse;
use Swoole\Http\Response as SwooleResponse;

final class SwooleResponseEmitter implements ResponseEmitterInterface
{
    public function emit(HttpResponse $response, mixed $transport): void
    {
        if (!$transport instanceof SwooleResponse) {
            throw new \InvalidArgumentException(
                'SwooleResponseEmitter expects a Swoole\Http\Response, got ' . get_debug_type($transport)
            );
        }

        // alreadySent ⇒ a handler already took exclusive ownership of the raw
        // Swoole Response (e.g. SSE streaming) and produced status, headers,
        // body, and end() itself. Touching the Response again would race the
        // freed connection state and SIGSEGV on Swoole 6.2.1.
        if ($response->isAlreadySent()) {
            return;
        }

        $transport->status($response->getStatusCode());

        $headers = self::withEncodingVary($response->getHeaders(), $response->getContent());

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                if (strtolower($name) === 'set-cookie') {
                    foreach ($value as $cookieLine) {
                        $transport->rawCookie(...self::parseCookieLine((string) $cookieLine));
                    }
                } else {
                    $transport->header($name, implode(', ', array_map('strval', $value)));
                }
            } else {
                $transport->header($name, (string) $value);
            }
        }

        $transport->end($response->getContent());
    }

    /**
     * Declare that this response depends on Accept-Encoding, because it does.
     *
     * Swoole compresses a response it `end()`s whenever the client accepts an
     * encoding and the body is at least `compression_min_length` — and it does
     * NOT emit `Vary`. Measured on a live server: the gzip answer carried
     * `Content-Encoding: gzip` and no Vary at all. Without it a shared cache
     * may store whichever representation it saw first under the bare URL and
     * hand it to everyone behind it — and in the bad direction that is a gzip
     * body served to a client that told us it cannot read one.
     *
     * Declared from the RESPONSE, not from the request that arrived: Vary
     * describes what this resource depends on, so a header that flipped with
     * the caller's own Accept-Encoding would describe a different resource to
     * each of them. The only input is the body length, which is a property of
     * the response.
     *
     * The type is not consulted because Swoole does not consult it either:
     * `http_compression_types` is null unless an application sets it, and the
     * content-type filter is skipped entirely when it is — so every body over
     * the floor is a candidate, whatever it contains.
     *
     * An existing Vary is extended rather than replaced. A response that
     * already varies on Cookie or Accept-Language still varies on those, and
     * overwriting that list would make a private response look shareable.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private static function withEncodingVary(array $headers, string $content): array
    {
        // Swoole's SW_COMPRESSION_MIN_LENGTH_DEFAULT. Below it nothing is
        // compressed, so nothing varies and a Vary would split a cache key for
        // a resource that has exactly one representation.
        if (strlen($content) < 20) {
            return $headers;
        }

        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'vary') {
                continue;
            }

            $existing = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;

            if ($existing === '*' || self::listsAcceptEncoding($existing)) {
                return $headers;
            }

            $headers[$name] = $existing === '' ? 'Accept-Encoding' : $existing . ', Accept-Encoding';

            return $headers;
        }

        $headers['Vary'] = 'Accept-Encoding';

        return $headers;
    }

    private static function listsAcceptEncoding(string $vary): bool
    {
        foreach (explode(',', strtolower($vary)) as $field) {
            if (trim($field) === 'accept-encoding') {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a raw Set-Cookie header line into arguments for Swoole's rawCookie().
     *
     * Cookie attributes (Secure, HttpOnly, SameSite) are parsed from the
     * Set-Cookie line when present. Callers should set these attributes
     * explicitly at the cookie creation site (e.g. CookieJar) based on
     * environment and request scheme.
     *
     * @return array{0: string, 1: string, 2: int, 3: string, 4: string, 5: bool, 6: bool, 7: string}
     */
    private static function parseCookieLine(string $line): array
    {
        $parts = array_map('trim', explode(';', $line));
        [$name, $value] = explode('=', $parts[0], 2) + [1 => ''];

        $expires = 0;
        $path = '/';
        $domain = '';
        // Defaults — can be overridden by explicit cookie attributes in the Set-Cookie line
        $secure = false;
        $httpOnly = false;
        $sameSite = 'Lax';

        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $attr = $parts[$i];
            $lower = strtolower($attr);

            if (str_starts_with($lower, 'expires=')) {
                $expires = (int) strtotime(substr($attr, 8));
            } elseif (str_starts_with($lower, 'max-age=')) {
                $expires = time() + (int) substr($attr, 8);
            } elseif (str_starts_with($lower, 'path=')) {
                $path = substr($attr, 5);
            } elseif (str_starts_with($lower, 'domain=')) {
                $domain = substr($attr, 7);
            } elseif ($lower === 'secure') {
                $secure = true;
            } elseif ($lower === 'httponly') {
                $httpOnly = true;
            } elseif (str_starts_with($lower, 'samesite=')) {
                $sameSite = substr($attr, 9);
            }
        }

        return [$name, $value, $expires, $path, $domain, $secure, $httpOnly, $sameSite];
    }
}
