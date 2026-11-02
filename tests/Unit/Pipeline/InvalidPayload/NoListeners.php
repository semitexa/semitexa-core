<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload;

/** Duck-typed stand-in for the listener registry: PipelineExecutor only calls getListeners(). */
final class NoListeners
{
    /** @return list<array{class: class-string, phase: string, priority: int}> */
    public function getListeners(string $phaseClass): array
    {
        return [];
    }
}
