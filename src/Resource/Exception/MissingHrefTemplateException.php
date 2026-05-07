<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use RuntimeException;

/**
 * Thrown when IriBuilder is asked to resolve a relation that does not declare
 * an href template in its metadata. The handler should not be calling
 * IriBuilder::forRelation for relations without a template.
 */
final class MissingHrefTemplateException extends RuntimeException
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $relation,
    ) {
        parent::__construct(sprintf(
            'IriBuilder: relation "%s::%s" declares no href template. Add `href: "..."` to the relation attribute, or do not call IriBuilder for this relation.',
            $resourceType,
            $relation,
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
