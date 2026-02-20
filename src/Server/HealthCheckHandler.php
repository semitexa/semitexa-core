<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

readonly class HealthCheckHandler
{
    public function handle(SwooleRequest $request, SwooleResponse $response): bool
    {
        if (($request->server['request_uri'] ?? '') !== '/health') {
            return false;
        }

        $response->status(200);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status' => 'ok',
            'timestamp' => time(),
        ], JSON_THROW_ON_ERROR));
        return true;
    }
}
