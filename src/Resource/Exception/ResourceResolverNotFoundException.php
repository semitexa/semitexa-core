<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6d: thrown when the runtime container cannot produce an
 * instance of a `#[ResolveWith]` resolver class that
 * `lint:resources` had previously validated.
 *
 * This is a **server configuration** failure (HTTP 500) — the
 * resolver was statically valid (`#[AsService]`, implements the
 * interface) but the live container cannot build it. The most likely
 * cause is missing or misconfigured DI for a transitively-required
 * dependency.
 */
final class ResourceResolverNotFoundException extends DomainException
{
    public function __construct(
        public readonly string $resolverClass,
        public readonly string $relationName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Resolver class %s (declared on relation "%s") could not be resolved from the container. '
                . 'Check that the resolver is `#[AsService]` and that all transitively required '
                . 'dependencies are registered.',
            $resolverClass,
            $relationName,
        ), 0, $previous);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::InternalServerError;
    }

    public function getErrorContext(): array
    {
        return [
            'resolver_class' => $this->resolverClass,
            'relation'       => $this->relationName,
        ];
    }
}
