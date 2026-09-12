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

        $headers = self::withEncodingVary($response->getHeaders());

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
     * Declare that this response depends on Accept-Encoding, because it may.
     *
     * Swoole compresses a response it `end()`s whenever the client accepts an
     * encoding and the body clears `compression_min_length` — and it does NOT
     * emit `Vary`. Measured on a live server: the gzip answer carried
     * `Content-Encoding: gzip` and no Vary at all. Without it a shared cache
     * may store whichever representation it saw first under the bare URL and
     * hand it to everyone behind it — and in the bad direction that is a gzip
     * body served to a client that told us it cannot read one.
     *
     * Declared for EVERY response rather than only for bodies over some length.
     * The first version tested `strlen($content) >= 20`, which is Swoole's
     * default `compression_min_length` copied into this file — a second place
     * that has to agree with a value it does not own and does not mention. An
     * application may lower that setting, and the copy would then withhold the
     * header from responses Swoole compresses, which is precisely the
     * cache-poisoning this exists to prevent. Over-declaring on a body too
     * short to compress splits a cache key for a resource nobody caches;
     * under-declaring hands a client bytes it cannot read. Those are not
     * comparable risks, so the cheap direction wins and the duplicated
     * constant goes.
     *
     * An existing Vary is extended rather than replaced. A response that
     * already varies on Cookie or Accept-Language still varies on those, and
     * overwriting that list would make a private response look shareable.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private static function withEncodingVary(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'vary') {
                continue;
            }

            $existing = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;

            // `*` already says the response is effectively uncacheable, and
            // trimmed because a hand-written header may carry spaces — turning
            // « * » into « * , Accept-Encoding » narrows a wildcard into a
            // list, which claims the opposite of what it said.
            if (trim($existing) === '*' || self::listsAcceptEncoding($existing)) {
                return $headers;
            }

            $headers[$name] = trim($existing) === '' ? 'Accept-Encoding' : $existing . ', Accept-Encoding';

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
