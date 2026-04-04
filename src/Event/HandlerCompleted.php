<?php

declare(strict_types=1);

namespace Semitexa\Core\Event;

use Semitexa\Core\Attribute\AsEvent;

#[AsEvent]
final class HandlerCompleted
{
    public function __construct(
        public readonly string $handlerClass,
        public readonly object $resource,
        public readonly string $handle,
    ) {}
}
