<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ResourceField
{
    public function __construct(
        public readonly string $description = '',
        public readonly bool $deprecated = false,
    ) {
    }
}
