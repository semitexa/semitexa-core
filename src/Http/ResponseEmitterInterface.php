<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\HttpResponse;

interface ResponseEmitterInterface
{
    /**
     * Emit a framework HttpResponse to the underlying transport.
     *
     * @param HttpResponse $response The framework response to emit
     * @param mixed $transport The transport-specific response object (e.g. Swoole\Http\HttpResponse)
     */
    public function emit(HttpResponse $response, mixed $transport): void;
}
