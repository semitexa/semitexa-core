<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ResourceRef
{
    /** @param class-string $target */
    public function __construct(
        public readonly string $target,
        public readonly bool $expandable = false,
        public readonly ?string $include = null,
        public readonly ?string $href = null,
        public readonly string $description = '',
    ) {
    }
}
