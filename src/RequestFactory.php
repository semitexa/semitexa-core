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

        $server = self::normalizeServerArray($swooleRequest->server ?? []);
        $uri = $server['request_uri'] ?? $server['REQUEST_URI'] ?? $server['path_info'] ?? '/';
        if ($uri === '' || $uri === false) {
            $uri = '/';
        }

        return new Request(
            method: $method,
            uri: $uri,
            headers: self::normalizeStringMap($swooleRequest->header ?? []),
            query: self::normalizeFormMap($swooleRequest->get ?? []),
            post: self::normalizeFormMap($post),
            server: array_merge($server, ['swoole_server' => '1']),
            cookies: self::normalizeStringMap($cookies),
            content: $content
        );
    }

    /**
     * Create Request from array data
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): Request
    {
        return new Request(
            method: is_string($data['method'] ?? null) ? $data['method'] : 'GET',
            uri: is_string($data['uri'] ?? null) ? $data['uri'] : '/',
            headers: self::normalizeStringMap($data['headers'] ?? []),
            query: self::normalizeFormMap($data['query'] ?? []),
            post: self::normalizeFormMap($data['post'] ?? []),
            server: self::normalizeServerArray($data['server'] ?? []),
            cookies: self::normalizeStringMap($data['cookies'] ?? []),
            content: is_string($data['content'] ?? null) ? $data['content'] : null
        );
    }

    /**
     * Parse application/x-www-form-urlencoded body (e.g. form POST).
     *
     * @return array<string, string|array<mixed>>
     */
    private static function parseFormUrlEncoded(string $body): array
    {
        $decoded = [];
        parse_str($body, $decoded);
        return self::normalizeFormMap($decoded);
    }

    /**
     * @param mixed $headers
     */
    private static function getHeader(mixed $headers, string $name): ?string
    {
        if (!is_array($headers)) {
            return null;
        }
        $nameLower = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $nameLower) {
                return is_string($v) ? $v : (string) $v;
            }
        }
        return null;
    }

    /**
     * Coerce an opaque map (Swoole property, JSON, etc.) into array<string, string>.
     * Non-scalar values are dropped; non-string keys are stringified.
     *
     * @return array<string, string>
     */
    private static function normalizeStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($v)) {
                $out[(string) $k] = $v;
            } elseif (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * Coerce an opaque map into the parse_str-style shape: scalar → string, array → array<mixed>.
     *
     * @return array<string, string|array<mixed>>
     */
    private static function normalizeFormMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $out[(string) $k] = $v;
            } elseif (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            }
        }
        return $out;
    }

    /**
     * @param mixed $server
     * @return array<string, mixed>
     */
    private static function normalizeServerArray(mixed $server): array
    {
        if (!is_array($server)) {
            return [];
        }

        $normalized = [];
        foreach ($server as $key => $value) {
            $normalized[strtolower((string) $key)] = $value;
        }

        return $normalized;
    }

    /**
     * Parse Cookie header into [name => value, ...]. Used when Swoole ->cookie is empty.
     *
     * @return array<string, string>
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
}
