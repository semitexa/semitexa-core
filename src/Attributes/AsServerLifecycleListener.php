<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsServerLifecycleListener
{
    public function __construct(
        public readonly string $phase,
        public readonly int $priority = 0,
        public readonly bool $requiresContainer = true,
    ) {
    }
}
