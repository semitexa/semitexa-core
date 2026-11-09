<?php

declare(strict_types=1);

namespace Semitexa\Core\Error;

use Semitexa\Core\Request;

final readonly class ErrorPageContext
{
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase,
        public string $publicMessage,
        public string $requestPath,
        public string $requestMethod,
        public ?string $requestId,
        public bool $debugEnabled,
        public ?string $exceptionClass,
        public ?string $debugMessage,
        public ?string $trace,
        public ?string $originalRouteName,
        /**
         * Where to send the visitor back to, as they asked for it: the served
         * path (a locale prefix kept) and the query string. `$requestPath` is
         * the routed path, which under LOCALE_URL_PREFIX drops the prefix, so a
         * sign-in form on `error.401` that returned there would land a `/uk/...`
         * guest in the default language and lose `?cart=123`.
         */
        public string $returnTo = '/',
    ) {}

    /**
     * The served path plus the query string of $request: the address the
     * visitor typed or followed, for a link back to it.
     *
     * The query is the raw one the server received when it has it. Under
     * Swoole getQueryString() rebuilds it from the parsed parameters, and
     * http_build_query() is not the inverse of parsing: `?a=1&a=2` comes back
     * as `a=2`, `?preview` as `preview=`.
     *
     * A browser sends `\` in a query unencoded, and every redirect guard
     * refuses a backslash (a browser reads one in a path as `/`), so
     * `/search?q=C:\Users` would send the visitor home. It is written as
     * `%5C`, which the server decodes to the same value.
     */
    public static function returnToOf(Request $request): string
    {
        $raw = $request->server['query_string'] ?? $request->server['QUERY_STRING'] ?? null;
        $query = str_replace('\\', '%5C', is_string($raw) && $raw !== '' ? $raw : $request->getQueryString());

        return $request->getServedPath() . ($query !== '' ? '?' . $query : '');
    }
}
