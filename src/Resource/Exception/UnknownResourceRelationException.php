<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use RuntimeException;

/**
 * Thrown when IriBuilder is asked to resolve a relation name that does not
 * exist on the parent Resource DTO's metadata.
 */
final class UnknownResourceRelationException extends RuntimeException
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $relation,
    ) {
        parent::__construct(sprintf(
            'IriBuilder: no field "%s" on resource "%s".',
            $relation,
            $resourceType,
        ));
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getRelation(): string
    {
        return $this->relation;
    }
}
