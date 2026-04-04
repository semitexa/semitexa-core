<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsPipelineListener
{
    public function __construct(
        public readonly string $phase,
        public readonly int $priority = 0,
    ) {
    }
}
