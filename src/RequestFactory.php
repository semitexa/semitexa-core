<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Http\UploadedFile;

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
            content: $content,
            files: self::normalizeFiles($swooleRequest->files ?? []),
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
            content: is_string($data['content'] ?? null) ? $data['content'] : null,
            files: self::normalizeFiles($data['files'] ?? []),
        );
    }

    /**
     * Convert the multi-shape Swoole / $_FILES array into a typed
     * UploadedFile map. Supports three shapes:
     *
     *   1) Already-typed: ['avatar' => UploadedFile, ...] → pass through.
     *   2) Single-file:   ['avatar' => ['name' => ..., 'tmp_name' => ..., ...]] → one UploadedFile.
     *   3) Multi-file:    ['attachments' => [['name'=>...,'tmp_name'=>...], ...]]
     *      OR             ['attachments' => ['name'=>[...], 'tmp_name'=>[...], ...]]
     *      → list<UploadedFile>.
     *
     * Anything that doesn't match is dropped — RequestFactory must never
     * pass random untyped data into Request::$files.
     *
     * @param mixed $files
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public static function normalizeFiles(mixed $files): array
    {
        if (!is_array($files)) {
            return [];
        }

        $out = [];
        foreach ($files as $field => $entry) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if ($entry instanceof UploadedFile) {
                $out[$field] = $entry;
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            // Already a list of UploadedFile — accept as-is.
            if (array_is_list($entry) && self::isUploadedFileList($entry)) {
                /** @var list<UploadedFile> $entry */
                $out[$field] = $entry;
                continue;
            }

            // Shape 2: single-file scalar entry from Swoole.
            if (isset($entry['name']) && !is_array($entry['name'])) {
                /** @var array<string, mixed> $entry */
                $out[$field] = UploadedFile::fromSwooleEntry($field, $entry);
                continue;
            }

            // Shape 3a: list of single-file entries.
            if (array_is_list($entry)) {
                $list = [];
                foreach ($entry as $sub) {
                    if (is_array($sub) && isset($sub['name']) && !is_array($sub['name'])) {
                        /** @var array<string, mixed> $sub */
                        $list[] = UploadedFile::fromSwooleEntry($field, $sub);
                    }
                }
                if ($list !== []) {
                    $out[$field] = $list;
                }
                continue;
            }

            // Shape 3b: $_FILES-style array of arrays — `name`, `type`, `tmp_name`, … all parallel lists.
            if (isset($entry['name']) && is_array($entry['name'])) {
                /** @var array<string, mixed> $entry */
                $list = self::explodeParallelFileEntry($field, $entry);
                if ($list !== []) {
                    $out[$field] = $list;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $list
     */
    private static function isUploadedFileList(array $list): bool
    {
        foreach ($list as $item) {
            if (!$item instanceof UploadedFile) {
                return false;
            }
        }
        return $list !== [];
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<UploadedFile>
     */
    private static function explodeParallelFileEntry(string $field, array $entry): array
    {
        $names = is_array($entry['name'] ?? null) ? $entry['name'] : [];
        $count = count($names);
        if ($count === 0) {
            return [];
        }

        $types = is_array($entry['type'] ?? null) ? $entry['type'] : [];
        $tmps = is_array($entry['tmp_name'] ?? null) ? $entry['tmp_name'] : [];
        $errors = is_array($entry['error'] ?? null) ? $entry['error'] : [];
        $sizes = is_array($entry['size'] ?? null) ? $entry['size'] : [];

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $list[] = UploadedFile::fromSwooleEntry($field, [
                'name' => $names[$i] ?? '',
                'type' => $types[$i] ?? '',
                'tmp_name' => $tmps[$i] ?? '',
                'error' => $errors[$i] ?? UPLOAD_ERR_OK,
                'size' => $sizes[$i] ?? 0,
            ]);
        }
        return $list;
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
