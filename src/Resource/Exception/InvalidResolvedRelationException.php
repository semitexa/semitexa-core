<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;

/**
 * Phase 6d: thrown when a `RelationResolverInterface::resolveBatch()`
 * call returns a value whose shape does not match the expected
 * contract:
 *
 *   - non-string key (the contract requires `ResourceIdentity::urn()` strings),
 *   - to-one relation returned a list,
 *   - to-many relation returned a non-list,
 *   - list contains an item that is not `ResourceObjectInterface`,
 *   - to-many relation returned `null` (the contract requires either a
 *     list or an empty list — never `null` for collections).
 *
 * Server bug (HTTP 500). The resolver implementation is wrong.
 */
final class InvalidResolvedRelationException extends DomainException
{
    public function __construct(
        public readonly string $resolverClass,
        public readonly string $relationName,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Resolver %s returned an invalid value for relation "%s": %s.',
            $resolverClass,
            $relationName,
            $reason,
        ));
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
            'reason'         => $this->reason,
        ];
    }
}
