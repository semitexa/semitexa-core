<?php

declare(strict_types=1);

namespace Semitexa\Core;

/**
 * Request Factory for creating Request objects from different sources
 */
class RequestFactory
{
    /**
     * Create Request (Swoole-only)
     */
    public static function create(mixed $source = null): Request
    {
        if ($source instanceof \Swoole\Http\Request) {
            return self::fromSwoole($source);
        }
        
        throw new \InvalidArgumentException('RequestFactory::create requires a Swoole\\Http\\Request in Swoole-only mode');
    }
    
    // fromGlobals removed in Swoole-only mode
    
    /**
     * Create Request from Swoole request object
     */
    public static function fromSwoole(\Swoole\Http\Request $swooleRequest): Request
    {
        // Get raw content - try both getContent() and rawContent() methods
        // Swoole's getContent() may return false for empty body, rawContent() is an alias
        $rawContent = $swooleRequest->getContent();
        if ($rawContent === false && method_exists($swooleRequest, 'rawContent')) {
            $rawContent = $swooleRequest->rawContent();
        }
        $content = ($rawContent !== false && $rawContent !== '') ? $rawContent : null;

        $method = strtoupper($swooleRequest->server['request_method'] ?? 'GET');
        $post = $swooleRequest->post ?? [];

        // Fallback: Swoole sometimes does not populate ->post for application/x-www-form-urlencoded
        if ($method === 'POST' && $post === [] && $content !== null && $content !== '') {
            $cType = self::getHeader($swooleRequest->header ?? [], 'content-type');
            if ($cType === null || str_contains(strtolower((string) $cType), 'application/x-www-form-urlencoded')) {
                $parsed = self::parseFormUrlEncoded($content);
                if ($parsed !== []) {
                    $post = $parsed;
                }
            }
        }

        $swooleCookies = $swooleRequest->cookie ?? [];
        $cookieHeader = self::getHeader($swooleRequest->header ?? [], 'cookie');
        $cookies = $swooleCookies;
        if ($cookieHeader !== null && $cookieHeader !== '') {
            $parsed = self::parseCookieHeader($cookieHeader);
            $cookies = array_merge($parsed, $cookies);
        }

        \Semitexa\Core\Debug\SessionDebugLog::log('RequestFactory.fromSwoole', [
            'method' => $method,
            'uri' => $swooleRequest->server['request_uri'] ?? '',
            'swoole_cookie_keys' => array_keys($swooleCookies),
            'has_cookie_header' => $cookieHeader !== null && $cookieHeader !== '',
            'cookie_header_len' => $cookieHeader !== null ? strlen($cookieHeader) : 0,
            'final_cookie_keys' => array_keys($cookies),
            'post_keys' => array_keys($post),
        ]);

        return new Request(
            method: $method,
            uri: $swooleRequest->server['request_uri'] ?? '/',
            headers: $swooleRequest->header ?? [],
            query: $swooleRequest->get ?? [],
            post: $post,
            server: array_merge($swooleRequest->server ?? [], ['SWOOLE_SERVER' => '1']),
            cookies: $cookies,
            content: $content
        );
    }
    
    /**
     * Create Request from array data
     */
    public static function fromArray(array $data): Request
    {
        return new Request(
            method: $data['method'] ?? 'GET',
            uri: $data['uri'] ?? '/',
            headers: $data['headers'] ?? [],
            query: $data['query'] ?? [],
            post: $data['post'] ?? [],
            server: $data['server'] ?? [],
            cookies: $data['cookies'] ?? [],
            content: $data['content'] ?? null
        );
    }
    
    /**
     * Parse application/x-www-form-urlencoded body (e.g. form POST).
     */
    private static function parseFormUrlEncoded(string $body): array
    {
        $decoded = [];
        parse_str($body, $decoded);
        return is_array($decoded) ? $decoded : [];
    }

    private static function getHeader(array $headers, string $name): ?string
    {
        $nameLower = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $nameLower) {
                return is_string($v) ? $v : (string) $v;
            }
        }
        return null;
    }

    /**
     * Parse Cookie header into [name => value, ...]. Used when Swoole ->cookie is empty.
     */
    private static function parseCookieHeader(string $header): array
    {
        $out = [];
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($part, 0, $eq));
            $value = trim(substr($part, $eq + 1));
            if ($name !== '') {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    private static function getHeaders(): array
    {
        $headers = [];
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback for servers without getallheaders()
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headerName = str_replace('_', '-', substr($key, 5));
                    $headers[$headerName] = $value;
                }
            }
        }
        
        return $headers;
    }
}
