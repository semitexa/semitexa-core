<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Attribute;

/**
 * Marks a trait as an extension part of a payload (request) DTO.
 *
 * Modules can provide payload parts that will be composed into the DTO instance
 * at runtime via dynamic class composition (eval'd wrapper class).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsPayloadPart
{
    public readonly ?string $doc;

    public function __construct(
        /**
         * Fully-qualified class name of the base payload/request that this part targets.
         */
        public string $base,
        ?string $doc = null
    ) {
        $this->doc = $doc;
    }
}
