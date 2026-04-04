<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;
use Semitexa\Core\Event\EventExecution;

/**
 * Marks a class as an event listener for the given event type.
 * Implement handle(EventType $event): void.
 *
 * The `execution` parameter is REQUIRED — no default. You must explicitly choose
 * Sync, Async (Swoole defer), or Queued.
 *
 * Implies #[ExecutionScoped]: a fresh clone is created for each execution.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsEventListener
{
    public EventExecution $execution;

    public function __construct(
        public string $event,
        EventExecution|string $execution,
        public ?string $transport = null,
        public ?string $queue = null,
        public ?int $priority = null,
    ) {
        $this->execution = match (true) {
            $execution instanceof EventExecution => $execution,
            is_string($execution) => EventExecution::fromAttributeValue($execution),
        };
    }
}
