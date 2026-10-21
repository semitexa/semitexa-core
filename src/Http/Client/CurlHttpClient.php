<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Http\Exception\HttpTransportException;

/**
 * HttpClientInterface over ext-curl. Under the Swoole server curl is hooked
 * (SWOOLE_HOOK_ALL includes SWOOLE_HOOK_CURL), so a call yields its worker to
 * other requests instead of blocking it; in the CLI it blocks, as a command
 * expects. The hook re-implements curl in PHP: it refuses options it does not
 * know (MAXFILESIZE, XFERINFOFUNCTION among them), speaks HTTP/1.1 only, and
 * left alone offers gzip and hands a WRITEFUNCTION the compressed bytes.
 *
 * So the body is always collected by a WRITEFUNCTION that enforces
 * maxResponseBytes as bytes arrive, the request asks for `identity`, and a
 * body encoded anyway is inflated here with the same cap — a decompression
 * bomb stops at the cap instead of in memory. Redirects are followed here,
 * not by curl, so a hop to another origin cannot carry the credentials.
 * A fresh handle per attempt: nothing is shared between coroutines.
 */
#[SatisfiesServiceContract(of: HttpClientInterface::class)]
final class CurlHttpClient implements HttpClientInterface
{
    use SendsConvenienceRequests;

    private const MAX_REDIRECTS = 5;

    /** Headers a request keeps when a redirect leaves its origin. */
    private const CROSS_ORIGIN_SAFE_HEADERS = ['accept', 'accept-language', 'user-agent'];

    /** @var (\Closure(int): void)|null */
    private ?\Closure $sleep = null;

    /** @var (\Closure(int, int): int)|null */
    private ?\Closure $random = null;

    /**
     * For tests: how a retry waits and draws its jitter.
     *
     * @param \Closure(int): void $sleep milliseconds
     * @param \Closure(int, int): int $random
     */
    public static function withTiming(\Closure $sleep, \Closure $random): self
    {
        $client = new self();
        $client->sleep = $sleep;
        $client->random = $random;

        return $client;
    }

    public function send(HttpRequest $request, ?HttpCallOptions $options = null): HttpResponse
    {
        $options ??= new HttpCallOptions();
        if ($options->followRedirects && $options->pinnedResolve !== []) {
            throw new \LogicException('pinnedResolve with followRedirects: a redirect would reach a host nobody checked.');
        }

        $started = hrtime(true);
        $response = $this->sendFollowing($request, $options);

        return $response->withAttempts($response->attempts, (hrtime(true) - $started) / 1_000_000);
    }

    private function sendFollowing(HttpRequest $request, HttpCallOptions $options): HttpResponse
    {
        for ($hop = 0; ; $hop++) {
            $response = $this->sendRetrying($request, $options);
            $location = $response->header('Location');
            if (!$options->followRedirects || $location === null || !in_array($response->status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            if ($hop >= self::MAX_REDIRECTS) {
                throw new HttpTransportException(HttpTransportKind::Protocol, $request->method, $request->url, 'more than ' . self::MAX_REDIRECTS . ' redirects');
            }
            $request = self::redirected($request, $response->status, $location, $options);
        }
    }

    private function sendRetrying(HttpRequest $request, HttpCallOptions $options): HttpResponse
    {
        $policy = $options->retry;
        for ($attempt = 1; ; $attempt++) {
            $forwarded = false;
            try {
                $response = $this->once($request, $options, $forwarded);
            } catch (HttpTransportException $e) {
                // A stream that already handed bytes on cannot be replayed: the
                // caller would get the first attempt's half and then the second.
                if (!$forwarded && $e->isRetryable() && $policy->appliesTo($request->method) && $attempt < $policy->maxAttempts) {
                    $this->pause($policy->delayMs($attempt, null, $this->random()));
                    continue;
                }

                throw new HttpTransportException($e->kind, $request->method, $request->url, $e->detail, $attempt);
            }

            if (!$forwarded && $policy->retriesStatus($response->status) && $policy->appliesTo($request->method) && $attempt < $policy->maxAttempts) {
                $this->pause($policy->delayMs($attempt, RetryPolicy::retryAfterMs($response->header('Retry-After'), time()), $this->random()));
                continue;
            }

            return $response->withAttempts($attempt, 0.0);
        }
    }

    private function once(HttpRequest $request, HttpCallOptions $options, bool &$forwarded): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new \LogicException('The HTTP client needs the curl extension (ext-curl).');
        }

        $rawHeaders = '';
        $status = 0;
        $body = '';
        $received = 0;
        $tooLarge = false;
        /** @var resource|false|null $held false: no temp stream could be opened, the body stays a string */
        $held = null;
        $heldShort = false;
        $max = $options->maxResponseBytes;
        $truncate = $options->truncateOversized;
        $onChunk = $options->onChunk;

        $headerLines = [];
        foreach ($request->headers as $name => $values) {
            foreach ($values as $value) {
                $headerLines[] = $name . ': ' . $value;
            }
        }
        if ($request->header('Accept-Encoding') === null) {
            $headerLines[] = 'Accept-Encoding: identity';
        }
        if ($request->body === '' && in_array($request->method, ['POST', 'PUT', 'PATCH'], true) && $request->header('Content-Length') === null) {
            // An empty POST/PUT without Content-Length is refused by S3 (411) and others.
            $headerLines[] = 'Content-Length: 0';
        }

        $curlOptions = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($options->timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($options->connectTimeout * 1000)),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$rawHeaders, &$status): int {
                $rawHeaders .= $line;
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int) $m[1];
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$held, &$heldShort, &$received, &$tooLarge, &$forwarded, &$status, $max, $truncate, $onChunk): int {
                $length = strlen($chunk);
                $room = $max - $received;
                $received += $length;
                if ($received > $max) {
                    if (!$truncate) {
                        $tooLarge = true;

                        return 0;
                    }
                    // Keep what fits, read and drop the rest.
                    $chunk = $room > 0 ? substr($chunk, 0, $room) : '';
                    if ($chunk === '') {
                        return $length;
                    }
                }
                // Only a successful response is a stream; an error body is kept
                // for the caller to read, and is what a retry may discard.
                if ($onChunk !== null && $held === null && $status >= 200 && $status < 300) {
                    $forwarded = true;
                    $onChunk($chunk);
                } elseif ($onChunk !== null && $held !== false && ($status === 0 || $held !== null)) {
                    // The hook tells the status only after the body (below):
                    // a stream is held on a temp stream, which spills to disk
                    // past 2 MiB, not in a string the size of the object. Once
                    // holding, it holds the rest too: the order is kept.
                    $held ??= fopen('php://temp', 'w+b');
                    if ($held === false) {
                        $body .= $chunk;
                    } elseif (@fwrite($held, $chunk) !== strlen($chunk)) {
                        // The temp disk is full: stop here, or the part that
                        // fitted would be handed on as the whole body.
                        $heldShort = true;

                        return 0;
                    }
                } else {
                    $body .= $chunk;
                }

                return $length;
            },
        ];
        if ($request->method === 'HEAD') {
            // Without NOBODY a HEAD response announcing Content-Length makes
            // libcurl wait for a body that never comes, until the timeout.
            $curlOptions[CURLOPT_NOBODY] = true;
        }
        if ($request->body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($options->noProxy) {
            $curlOptions[CURLOPT_PROXY] = '';
            $curlOptions[CURLOPT_NOPROXY] = '*';
        }
        if ($options->pinnedResolve !== []) {
            $curlOptions[CURLOPT_RESOLVE] = $options->pinnedResolve;
        }
        if ($options->httpOnly) {
            $curlOptions[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        $handle = curl_init();
        try {
            curl_setopt_array($handle, $curlOptions);
            $ok = curl_exec($handle);
            $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $errno = curl_errno($handle);
            $error = curl_error($handle);
        } catch (\Throwable $e) {
            // The caller's onChunk threw mid-transfer: the held stream too.
            if (is_resource($held)) {
                fclose($held);
            }
            throw $e;
        } finally {
            // The hook's handle (Swoole\Curl\Handler) with a WRITEFUNCTION is
            // freed only by curl_close(): unset alone kept every response body
            // and its socket for the life of the worker (ten 1 MB GETs: +10
            // open sockets, +11.5 MB), and so did an onChunk that threw. A
            // native handle needs no close, and PHP 8.5 deprecates closing
            // one. By exact class: the hook's handle extends \CurlHandle, so
            // instanceof cannot tell them apart.
            if ($handle::class !== \CurlHandle::class) {
                curl_close($handle);
            }
            unset($handle);
        }

        if (is_resource($held) && ($tooLarge || $heldShort || $ok === false || $errno !== 0)) {
            fclose($held);
            $held = null;
        }
        if ($heldShort) {
            throw new HttpTransportException(HttpTransportKind::Protocol, $request->method, $request->url, 'the body could not be held on a temp stream (disk full?)');
        }
        if ($tooLarge) {
            throw new HttpTransportException(HttpTransportKind::TooLarge, $request->method, $request->url, "the body passed {$max} bytes");
        }
        if ($ok === false || $errno !== 0) {
            throw new HttpTransportException(self::kindOf($errno), $request->method, $request->url, $error !== '' ? $error : "curl error {$errno}");
        }

        [$block, $headers] = self::finalHeaders($rawHeaders);
        if (is_resource($held)) {
            // A held stream goes on in pieces once the status is known; an
            // error, or a body still to be inflated, is read back whole.
            $heldCode = $httpCode !== 0 ? $httpCode : $status;
            $encoding = strtolower($headers['content-encoding'][0] ?? 'identity');
            try {
                rewind($held);
                if ($onChunk !== null && $heldCode >= 200 && $heldCode < 300 && (!$options->decodeContent || $encoding === 'identity')) {
                    $forwarded = true;
                    while (($piece = fread($held, 65536)) !== false && $piece !== '') {
                        $onChunk($piece);
                    }
                } else {
                    $body = (string) stream_get_contents($held) . $body;
                }
            } finally {
                // Also when the callback throws: a caller that stops a stream
                // that way left the temp file open.
                fclose($held);
            }
        }
        if ($options->decodeContent) {
            $body = self::decoded($body, $headers['content-encoding'][0] ?? null, $max, $request);
        }
        $code = $httpCode !== 0 ? $httpCode : $status;

        // The hook calls WRITEFUNCTION before HEADERFUNCTION has told the
        // status, so a stream's body is held back above; hand it on now that
        // the response is known to be a success (in one piece, not as it came).
        if ($onChunk !== null && $code >= 200 && $code < 300 && $body !== '') {
            $forwarded = true;
            $onChunk($body);
            $body = '';
        }

        return new HttpResponse($code, $body, $headers, $block);
    }

    /**
     * The request for the next hop. 303 — and 301/302 after a POST, as every
     * client does — becomes a GET without a body; 307/308 keep both. A hop to
     * another origin keeps only harmless headers: Authorization, cookies and
     * API keys stay with the origin they were meant for.
     */
    private static function redirected(HttpRequest $request, int $status, string $location, HttpCallOptions $options): HttpRequest
    {
        $next = self::resolveUrl($request->url, $location);
        $scheme = strtolower((string) parse_url($next, PHP_URL_SCHEME));
        if ($options->httpOnly && $scheme !== 'http' && $scheme !== 'https') {
            throw new HttpTransportException(HttpTransportKind::Protocol, $request->method, $request->url, 'a redirect to a non-HTTP URL');
        }

        $method = $request->method;
        $body = $request->body;
        if ($status === 303 || (($status === 301 || $status === 302) && $method === 'POST')) {
            $method = $method === 'HEAD' ? 'HEAD' : 'GET';
            $body = '';
        }

        $headers = $request->headers;
        if (self::origin($next) !== self::origin($request->url)) {
            $headers = array_filter(
                $headers,
                static fn (string $name): bool => in_array(strtolower($name), self::CROSS_ORIGIN_SAFE_HEADERS, true),
                ARRAY_FILTER_USE_KEY,
            );
        }
        if ($body === '') {
            $headers = array_filter($headers, static fn (string $name): bool => strtolower($name) !== 'content-type' && strtolower($name) !== 'content-length', ARRAY_FILTER_USE_KEY);
        }

        return new HttpRequest($method, $next, $headers, $body);
    }

    private static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'http') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') ?? '/';

        return $origin . $dir . $location;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'http');

        return $scheme . '://' . strtolower($parts['host'] ?? '') . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * The final response's headers. A block starts at a status line: a
     * redirect or `100 Continue` leaves earlier blocks, and trailers after a
     * chunked body belong to the block they follow.
     *
     * @return array{0: string, 1: array<string, list<string>>}
     */
    private static function finalHeaders(string $raw): array
    {
        $block = '';
        $headers = [];
        foreach (preg_split("/\r?\n/", $raw) ?: [] as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $block = $line . "\r\n";
                $headers = [];
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $block .= $line . "\r\n";
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
            }
        }

        return [$block, $headers];
    }

    /** A body the server encoded although we asked for identity, inflated no further than the cap. */
    private static function decoded(string $body, ?string $encoding, int $max, HttpRequest $request): string
    {
        $encoding = strtolower(trim((string) $encoding));
        if ($body === '' || $encoding === '' || $encoding === 'identity') {
            return $body;
        }

        // One past the cap tells "over" from "exactly at"; PHP_INT_MAX + 1 is
        // a float, which gzdecode() refuses (a TypeError, not a limit).
        $limit = $max < PHP_INT_MAX ? $max + 1 : PHP_INT_MAX;
        $inflated = match ($encoding) {
            'gzip', 'x-gzip' => @gzdecode($body, $limit),
            'deflate' => @gzuncompress($body, $limit) ?: @gzinflate($body, $limit),
            default => throw new HttpTransportException(HttpTransportKind::Protocol, $request->method, $request->url, "an unsupported content encoding: {$encoding}"),
        };
        if (!is_string($inflated)) {
            // gzdecode fails both on a broken body and on one past max_length.
            throw new HttpTransportException(HttpTransportKind::TooLarge, $request->method, $request->url, "the {$encoding} body does not inflate within {$max} bytes");
        }
        if (strlen($inflated) > $max) {
            throw new HttpTransportException(HttpTransportKind::TooLarge, $request->method, $request->url, "the body passed {$max} bytes");
        }

        return $inflated;
    }

    private static function kindOf(int $errno): HttpTransportKind
    {
        return match ($errno) {
            6 => HttpTransportKind::Dns,                          // COULDNT_RESOLVE_HOST
            28 => HttpTransportKind::Timeout,                     // OPERATION_TIMEDOUT
            35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 => HttpTransportKind::Tls,
            63 => HttpTransportKind::TooLarge,                    // FILESIZE_EXCEEDED
            1, 3 => HttpTransportKind::Protocol,                  // UNSUPPORTED_PROTOCOL, URL_MALFORMAT
            default => HttpTransportKind::Connect,                // 7 refused, 52 empty reply, 55/56 send/recv…
        };
    }

    private function pause(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }
        if ($this->sleep !== null) {
            ($this->sleep)($milliseconds);

            return;
        }
        usleep($milliseconds * 1000);
    }

    /** @return \Closure(int, int): int */
    private function random(): \Closure
    {
        return $this->random ?? static fn (int $min, int $max): int => random_int($min, $max);
    }
}
