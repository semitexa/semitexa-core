<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * The path of an HTTP request-target, which is a path and not a URL.
 *
 * parse_url() reads a leading `//` as an authority and a colon as a port:
 * `//evil.com/admin` came back as `/admin`, slipping past any prefix rule a
 * proxy had applied to the raw target, and `/events/10:00` came back as false
 * and routed to `/`. Only an absolute-form target (`http://host/x`) is a URL,
 * so only that one is left to parse_url().
 */
final class RequestTarget
{
    public static function path(string $target): ?string
    {
        $path = str_starts_with($target, '/')
            ? substr($target, 0, strcspn($target, '?#'))
            : parse_url($target, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : null;
    }
}
