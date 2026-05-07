<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6c: thrown when a client requests an include token that is a
 * valid expandable relation in metadata but that the framework cannot
 * satisfy because:
 *
 *   - the relation has no `#[ResolveWith]` resolver registered, AND
 *   - the route's payload does not declare the token via
 *     `#[HandlerProvidesResourceIncludes]`.
 *
 * Maps to **HTTP 400** through the existing `ExceptionMapper`
 * `DomainException` route — this is a client-facing error, not a
 * server bug.
 *
 * The token may have been satisfiable in an earlier deploy; this is
 * not a 404 (the relation field exists) and not a 500 (the system is
 * working as configured).
 */
final class UnsatisfiedResourceIncludeException extends DomainException
{
    public function __construct(
        public readonly string $resourceType,
        public readonly string $token,
        public readonly string $relationName,
        public readonly bool $resolverMissing,
        public readonly bool $handlerContractMissing,
    ) {
        $reason = match (true) {
            $resolverMissing && $handlerContractMissing
                => 'no #[ResolveWith] resolver is registered and the route does not declare it as handler-provided',
            $resolverMissing
                => 'no #[ResolveWith] resolver is registered',
            $handlerContractMissing
                => 'the route does not declare it as handler-provided',
            default
                => 'unknown reason',
        };

        parent::__construct(sprintf(
            'Include token "%s" on resource "%s" cannot be satisfied: %s. '
                . 'Either add #[ResolveWith] to the relation, or declare the token via '
                . '#[HandlerProvidesResourceIncludes] on the payload.',
            $token,
            $resourceType,
            $reason,
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::BadRequest;
    }

    public function getErrorContext(): array
    {
        return [
            'token'                    => $this->token,
            'resource'                 => $this->resourceType,
            'relation'                 => $this->relationName,
            'resolver_missing'         => $this->resolverMissing,
            'handler_contract_missing' => $this->handlerContractMissing,
        ];
    }
}
