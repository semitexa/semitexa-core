<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Http\Exception\HttpTransportException;

/**
 * HttpClientInterface over ext-curl. Under the Swoole server curl is hooked
 * (SWOOLE_HOOK_ALL includes SWOOLE_HOOK_CURL), so a call yields its worker to
 * other requests instead of blocking it; in the CLI it blocks, as a command
 * expects. The hook re-implements curl in PHP and refuses options it does not
 * know, so only options it accepts are used here (pinned by the coroutine
 * test), and it speaks HTTP/1.1 only.
 *
 * A fresh handle per attempt: nothing is shared between coroutines.
 */
#[SatisfiesServiceContract(of: HttpClientInterface::class)]
final class CurlHttpClient implements HttpClientInterface
{
    use SendsConvenienceRequests;

    private const MAX_REDIRECTS = 5;

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
        $policy = $options->retry;
        $started = hrtime(true);

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->once($request, $options);
            } catch (HttpTransportException $e) {
                if ($e->isRetryable() && $policy->appliesTo($request->method) && $attempt < $policy->maxAttempts) {
                    $this->pause($policy->delayMs($attempt, null, $this->random()));
                    continue;
                }

                throw new HttpTransportException($e->kind, $request->method, $request->url, $e->detail, $attempt);
            }

            if ($policy->retriesStatus($response->status) && $policy->appliesTo($request->method) && $attempt < $policy->maxAttempts) {
                $this->pause($policy->delayMs($attempt, RetryPolicy::retryAfterMs($response->header('Retry-After'), time()), $this->random()));
                continue;
            }

            return $response->withAttempts($attempt, (hrtime(true) - $started) / 1_000_000);
        }
    }

    private function once(HttpRequest $request, HttpCallOptions $options): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new \LogicException('The HTTP client needs the curl extension (ext-curl).');
        }

        $rawHeaders = '';
        $body = '';
        $received = 0;
        $tooLarge = false;
        $max = $options->maxResponseBytes;
        $onChunk = $options->onChunk;

        $headerLines = [];
        foreach ($request->headers as $name => $values) {
            foreach ($values as $value) {
                $headerLines[] = $name . ': ' . $value;
            }
        }

        $curlOptions = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($options->timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ceil($options->connectTimeout * 1000)),
            CURLOPT_FOLLOWLOCATION => $options->followRedirects,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            // Offer what curl can decode, and decode it: the hook offers gzip
            // whatever we say, so the CLI must be able to read it the same way.
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$rawHeaders): int {
                $rawHeaders .= $line;

                return strlen($line);
            },
        ];
        if ($onChunk !== null) {
            // A stream: hand each chunk on as it arrives. Ask for it unencoded:
            // under the hook a WRITEFUNCTION gets gzip bytes as they came.
            if ($request->header('Accept-Encoding') === null) {
                $curlOptions[CURLOPT_HTTPHEADER][] = 'Accept-Encoding: identity';
                unset($curlOptions[CURLOPT_ENCODING]);
            }
            $curlOptions[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$received, &$tooLarge, $max, $onChunk): int {
                $received += strlen($chunk);
                if ($received > $max) {
                    $tooLarge = true;

                    return 0;
                }
                $onChunk($chunk);

                return strlen($chunk);
            };
        } else {
            // RETURNTRANSFER, not a WRITEFUNCTION collecting the body: under the
            // hook a WRITEFUNCTION receives the body still gzip-encoded.
            $curlOptions[CURLOPT_RETURNTRANSFER] = true;
        }
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
        curl_setopt_array($handle, $curlOptions);
        $ok = curl_exec($handle);
        if (is_string($ok)) {
            $body = $ok;
            $tooLarge = strlen($body) > $max;
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        unset($handle);

        if ($tooLarge) {
            throw new HttpTransportException(HttpTransportKind::TooLarge, $request->method, $request->url, "the body passed {$max} bytes");
        }
        if ($ok === false || $errno !== 0) {
            throw new HttpTransportException(self::kindOf($errno), $request->method, $request->url, $error !== '' ? $error : "curl error {$errno}");
        }

        [$block, $headers] = self::lastHeaderBlock($rawHeaders);

        return new HttpResponse($status, $body, $headers, $block);
    }

    /**
     * The final response's headers: a redirect or a `100 Continue` leaves
     * earlier blocks in front of it.
     *
     * @return array{0: string, 1: array<string, list<string>>}
     */
    private static function lastHeaderBlock(string $raw): array
    {
        $blocks = array_values(array_filter(preg_split("/\r?\n\r?\n/", $raw) ?: [], static fn (string $b): bool => trim($b) !== ''));
        $block = $blocks === [] ? '' : $blocks[count($blocks) - 1];

        $headers = [];
        foreach (preg_split("/\r?\n/", $block) ?: [] as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || str_starts_with($line, 'HTTP/')) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
        }

        return [$block, $headers];
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
