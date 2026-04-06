<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Swoole\Http\Request as SwooleRequest;
use Semitexa\Core\Http\HttpStatus;
use Swoole\Http\Response as SwooleResponse;

class HealthCheckHandler
{
    private ?string $buildHash = null;
    private bool $buildHashLoaded = false;

    private function getBuildHash(): ?string
    {
        if (!$this->buildHashLoaded) {
            $markerFile = \Semitexa\Core\Support\ProjectRoot::get() . '/var/runtime/build.hash';
            if (!is_file($markerFile)) {
                $this->buildHash = null;
            } else {
                $contents = file_get_contents($markerFile);
                $trimmed = $contents === false ? '' : trim($contents);
                $this->buildHash = $trimmed !== '' ? $trimmed : null;
            }
            $this->buildHashLoaded = true;
        }
        return $this->buildHash;
    }

    public function handle(SwooleRequest $request, SwooleResponse $response): bool
    {
        if (($request->server['request_uri'] ?? '') !== '/health') {
            return false;
        }

        $payload = [
            'status' => 'ok',
            'timestamp' => time(),
        ];

        $buildHash = $this->getBuildHash();
        if ($buildHash !== null) {
            $payload['build_hash'] = $buildHash;
        }

        $response->status(HttpStatus::Ok->value);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($payload, JSON_THROW_ON_ERROR));
        return true;
    }
}
