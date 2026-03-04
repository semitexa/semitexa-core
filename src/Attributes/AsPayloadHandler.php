<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Attribute;
use Semitexa\Core\Queue\HandlerExecution;

/**
 * Marks a class as an HTTP payload (request) handler.
 * Match by (payload, resource): this handler runs for the given Payload and builds the given Resource.
 *
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsPayloadHandler
{
    public readonly ?string $doc;

    public function __construct(
        public string $payload,
        public string $resource,
        public HandlerExecution|string|null $execution = null,
        public ?string $transport = null,
        public ?string $queue = null,
        public ?int $priority = null,
        public int $maxRetries = 0,
        public int $retryDelay = 0,
        ?string $doc = null,
    ) {
        $this->doc = $doc;
    }
}
