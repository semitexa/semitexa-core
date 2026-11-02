<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload;

use Psr\Container\ContainerInterface;

/** Hands out the services it was given (InvalidPayloadPipelineTest). */
final class FixedContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private readonly array $services) {}

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new \RuntimeException("no binding for {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
