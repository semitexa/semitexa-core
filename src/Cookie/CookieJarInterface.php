<?php

declare(strict_types=1);

namespace Semitexa\Core\Cookie;

/**
 * Read cookies from the current request; queue cookies to be sent with the response.
 */
interface CookieJarInterface
{
    public function get(string $name, ?string $default = null): ?string;

    public function has(string $name): bool;

    /**
     * Queue a cookie to be sent with the response.
     *
     * @param array{expires?: int, maxAge?: int, path?: string, domain?: string, secure?: bool, httpOnly?: bool, sameSite?: 'lax'|'strict'|'none'} $options
     */
    public function set(string $name, string $value, array $options = []): void;

    /** Remove cookie (send with past expiry). */
    public function remove(string $name, string $path = '/', ?string $domain = null): void;

    /** Get all Set-Cookie header values to add to the response. */
    public function getSetCookieLines(): array;
}
