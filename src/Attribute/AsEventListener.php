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
 *
 * Queued only: a listener that throws is retried `maxRetries` times, `retryDelay`
 * seconds apart, then moved to the dead-letter queue `<queue>.failed` — the same
 * policy as {@see AsPayloadHandler}. Ignored for Sync and Async, and reported
 * at boot when given there, as are a transport and a queue.
 *
 * @api
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
        public int $maxRetries = 0,
        public int $retryDelay = 0,
    ) {
        $this->execution = match (true) {
            $execution instanceof EventExecution => $execution,
            is_string($execution) => EventExecution::fromAttributeValue($execution),
        };
    }
}
