<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Environment;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

readonly class CorsHandler
{
    public function __construct(private Environment $env) {}

    /**
     * Handle CORS headers. Returns true if the request was fully handled (preflight).
     */
    public function handle(SwooleRequest $request, SwooleResponse $response): bool
    {
        $origin = $request->header['origin'] ?? null;
        if ($origin === null) {
            return false;
        }

        if (!$this->isAllowedOrigin($origin)) {
            return false;
        }

        $response->header('Access-Control-Allow-Origin', $this->resolveAllowOrigin($origin));
        $response->header('Vary', 'Origin');

        if ($this->env->corsAllowCredentials) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        if (($request->server['request_method'] ?? '') === 'OPTIONS') {
            $response->header('Access-Control-Allow-Methods', $this->env->corsAllowMethods);
            $response->header('Access-Control-Allow-Headers', $this->env->corsAllowHeaders);
            $response->header('Access-Control-Max-Age', '7200');
            $response->status(204);
            $response->end();
            return true;
        }

        return false;
    }

    private function isAllowedOrigin(string $origin): bool
    {
        if ($this->env->corsAllowOrigin === '*') {
            return true;
        }

        $allowed = array_map('trim', explode(',', $this->env->corsAllowOrigin));
        return in_array($origin, $allowed, true);
    }

    private function resolveAllowOrigin(string $origin): string
    {
        if ($this->env->corsAllowOrigin === '*' && !$this->env->corsAllowCredentials) {
            return '*';
        }

        // When credentials are enabled or origin is in explicit list, echo back the specific origin
        return $origin;
    }
}
