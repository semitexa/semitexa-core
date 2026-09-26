<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

/**
 * Ends a response written straight to Swoole, outside the Response pipeline
 * (health, metrics, the boot-gate 503, the last-resort 500).
 *
 * Swoole's HTTP server does not strip the body of a HEAD response. A body
 * written after HEAD headers is read by a keep-alive client as the start of
 * the NEXT response, so every such site has to drop it — the pipeline does
 * the same through {@see \Semitexa\Core\Http\SwooleResponseEmitter}.
 */
final class RawResponse
{
    public static function isHead(SwooleRequest $request): bool
    {
        /** @var array<string, mixed> $serverVars */
        $serverVars = $request->server ?? [];
        $method = $serverVars['request_method'] ?? '';

        return is_string($method) && strtoupper($method) === 'HEAD';
    }

    public static function end(SwooleRequest $request, SwooleResponse $response, string $body): void
    {
        $response->end(self::isHead($request) ? '' : $body);
    }

    private function __construct()
    {
    }
}
