<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use RuntimeException;

/**
 * Thrown when a renderer encounters a relation envelope whose `data` is null
 * but the active profile or include selection requires it to be embedded. v1
 * is eager-only — handlers must populate every selected relation before the
 * renderer runs.
 */
final class UnloadedRelationException extends RuntimeException
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $field,
    ) {
        parent::__construct(sprintf(
            'Relation "%s::%s" was selected but its data is null. The handler must embed every selected relation before rendering.',
            $resourceType,
            $field,
        ));
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getField(): string
    {
        return $this->field;
    }
}
