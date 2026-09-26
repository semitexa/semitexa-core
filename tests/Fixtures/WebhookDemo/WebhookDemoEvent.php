<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\WebhookDemo;

final readonly class WebhookDemoEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
    ) {}
}
