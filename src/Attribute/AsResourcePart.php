<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Marks a trait as an extension part of a resource (response) DTO.
 *
 * Modules can provide resource parts that will be composed into the DTO instance
 * at runtime via dynamic class composition (eval'd wrapper class).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsResourcePart
{
    public function __construct(
        /**
         * Fully-qualified class name of the base resource that this part targets.
         */
        public string $base
    ) {
    }
}
