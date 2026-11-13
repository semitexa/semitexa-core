<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

/**
 * A rendered field of a resource object, with its description.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ResourceField
{
    public function __construct(
        public readonly string $description = '',
        public readonly bool $deprecated = false,
    ) {
    }
}
