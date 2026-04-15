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

        $transport->status($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $value) {
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

        if (!$response->isAlreadySent()) {
            $transport->end($response->getContent());
        }
    }

    /**
     * Parse a raw Set-Cookie header line into arguments for Swoole's rawCookie().
     *
     * Security: defaults to secure cookie attributes (VULN-012):
     * - HttpOnly: true (prevent JavaScript access)
     * - Secure: true (require HTTPS)
     * - SameSite: Lax (CSRF protection)
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
        // Security defaults — can be overridden by explicit cookie attributes
        $secure = true;
        $httpOnly = true;
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
