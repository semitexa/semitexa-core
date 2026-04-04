<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use JsonException;
use Semitexa\Core\Http\HttpStatus;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;

/**
 * Exposes Swoole server stats at /metrics for observability.
 *
 * Includes: active connections, worker memory, coroutine count, request totals.
 */
final class MetricsHandler
{
    private Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function handle(SwooleRequest $request, SwooleResponse $response): bool
    {
        /** @var array<string, mixed> $serverVars */
        $serverVars = $request->server ?? [];
        if (($serverVars['request_uri'] ?? '') !== '/metrics') {
            return false;
        }

        if (!$this->isEnabled()) {
            return false;
        }

        if (!$this->isAuthorized($request)) {
            $response->status(HttpStatus::Forbidden->value);
            $response->header('Content-Type', 'application/json');
            $response->end('{}');

            return true;
        }

        /** @var array<string, mixed> $stats */
        $stats = $this->server->stats();
        $stats['worker_memory_usage'] = memory_get_usage(true);
        $stats['worker_memory_peak'] = memory_get_peak_usage(true);

        if (class_exists(\Swoole\Coroutine::class, false)) {
            /** @var array<string, int> $coStats */
            $coStats = \Swoole\Coroutine::stats();
            $stats['coroutine_num'] = $coStats['coroutine_num'] ?? 0;
        }

        $response->header('Content-Type', 'application/json');

        try {
            $payload = json_encode($stats, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $response->status(HttpStatus::Ok->value);
            $response->end($payload);
        } catch (JsonException) {
            $response->status(HttpStatus::InternalServerError->value);
            $response->end('{}');
        }

        return true;
    }

    private function isEnabled(): bool
    {
        $value = \Semitexa\Core\Environment::getEnvValue('SEMITEXA_METRICS_ENABLED', '0');
        $normalized = is_string($value) ? strtolower($value) : '';

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function isAuthorized(SwooleRequest $request): bool
    {
        /** @var array<string, mixed> $serverVars */
        $serverVars = $request->server ?? [];
        $remoteAddrValue = $serverVars['remote_addr'] ?? '';
        $remoteAddr = is_string($remoteAddrValue) ? $remoteAddrValue : '';

        $configuredTokenValue = \Semitexa\Core\Environment::getEnvValue('SEMITEXA_METRICS_TOKEN', '');
        $configuredToken = is_string($configuredTokenValue) ? $configuredTokenValue : '';

        /** @var array<string, mixed> $headers */
        $headers = $request->header ?? [];

        if ($configuredToken !== '') {
            $providedToken = $headers['x-metrics-token'] ?? $headers['X-Metrics-Token'] ?? null;

            return is_string($providedToken) && hash_equals($configuredToken, $providedToken);
        }

        if (
            in_array($remoteAddr, ['127.0.0.1', '::1'], true)
            && !$this->hasProxyHeaders($headers)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function hasProxyHeaders(array $headers): bool
    {
        foreach (['x-forwarded-for', 'forwarded', 'x-real-ip', 'true-client-ip'] as $header) {
            $value = $headers[$header] ?? $headers[strtoupper($header)] ?? $headers[ucwords($header, '-')] ?? null;
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
