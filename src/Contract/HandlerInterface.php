<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Contract for request handlers. Implement this and use #[AsPayloadHandler(payload: ..., resource: ...)].
 * Handlers are discovered by the kernel and invoked automatically; do not register them as service contracts.
 */
interface HandlerInterface
{
    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface;
}
