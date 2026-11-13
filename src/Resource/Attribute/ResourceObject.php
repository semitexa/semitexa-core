<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

/**
 * Declares a class as a resource object: a typed shape a JSON, JSON-LD, GraphQL or OpenAPI rendering is built from.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ResourceObject
{
    public function __construct(
        public readonly string $type,
        public readonly string $description = '',
        public readonly bool $deprecated = false,
    ) {
    }
}
