<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Exception;

use Semitexa\Core\Http\Client\HttpTransportKind;

/**
 * No response: the connection, DNS, TLS or the clock failed, or the body was
 * too large. The message names the method and scheme://host/path only — no
 * query string, header or body, where credentials and tokens travel.
 */
final class HttpTransportException extends \RuntimeException
{
    public function __construct(
        public readonly HttpTransportKind $kind,
        string $method,
        string $url,
        /** What the transport said, without the request's URL. */
        public readonly string $detail,
        public readonly int $attempts = 1,
    ) {
        parent::__construct(sprintf('%s %s failed (%s): %s', $method, self::safeUrl($url), $kind->value, $detail));
    }

    public function isRetryable(): bool
    {
        return $this->kind->isRetryable();
    }

    public static function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '(unparseable url)';
        }

        return ($parts['scheme'] ?? 'http') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');
    }
}
