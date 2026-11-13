<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

/**
 * A property holding a list of resource objects of the target class.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ResourceListOf
{
    /** @param class-string $target */
    public function __construct(
        public readonly string $target,
        public readonly string $description = '',
    ) {
    }
}
