<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use RuntimeException;

/**
 * Thrown when an href template references a `{placeholder}` for which the
 * caller did not supply a value. The metadata validator catches templates
 * that reference fields that don't exist on the parent at boot time; this
 * exception is the runtime version when the *value* is missing.
 */
final class MissingHrefTemplateValueException extends RuntimeException
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $relation,
        private readonly string $placeholder,
    ) {
        parent::__construct(sprintf(
            'IriBuilder: missing value for "{%s}" while resolving "%s::%s". Provide it in $parentValues.',
            $placeholder,
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

    public function getPlaceholder(): string
    {
        return $this->placeholder;
    }
}
