<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

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
