<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Marker interface for handlers that declare concrete Payload and Resource types
 * in their handle() signature. The pipeline uses reflection to inject the correct
 * instances — no instanceof checks required.
 *
 * Concrete handlers define:
 *   public function handle(ConcretePayload $payload, ConcreteResource $resource): ConcreteResource;
 *
 * The return type MUST be a concrete ResourceInterface implementation,
 * never a raw Response object.
 *
 * PHP interfaces cannot express covariant parameter types, so no handle() method
 * is declared here. The pipeline validates the signature at discovery time (boot)
 * and invokes via reflection-cached closures at runtime.
 */
interface TypedHandlerInterface
{
}
