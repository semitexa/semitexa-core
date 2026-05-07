<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class ResourceUnion
{
    /** @param list<class-string> $targets */
    public function __construct(
        public readonly array $targets,
        public readonly string $discriminator = 'type',
        public readonly bool $list = false,
        public readonly bool $expandable = false,
        public readonly ?string $include = null,
        public readonly string $description = '',
    ) {
    }
}
