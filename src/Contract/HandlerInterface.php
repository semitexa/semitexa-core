<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Contract for request handlers. Implement this and use #[AsPayloadHandler(payload: ..., resource: ...)].
 * Handlers are discovered by the kernel and invoked automatically; do not register them as service contracts.
 *
 * @deprecated Use TypedHandlerInterface instead. TypedHandlerInterface allows concrete
 *             Payload/Resource types in the handle() signature, eliminating instanceof checks.
 *             Will be removed in v2.0.
 */
interface HandlerInterface
{
    public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface;
}
