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
 * is declared here. The pipeline validates the handle() signature at discovery time
 * (boot) via HandlerReflectionCache::warm(): it checks that parameter 0 is a
 * concrete class, parameter 1 implements ResourceInterface, extra parameters are
 * optional, and the return type is not Response. Invocation uses the cached
 * ReflectionMethod at request time.
 */
interface TypedHandlerInterface
{
}
