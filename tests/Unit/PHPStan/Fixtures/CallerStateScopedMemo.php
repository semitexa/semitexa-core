<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;

/**
 * Fixture: the twin of CallerStateSharedMemo that gave 0 wrong answers. The
 * class is cloned per request, so what a caller leaves on it dies with the
 * request, and WorkerServiceCallerStateRule must stay silent.
 */
#[AsService]
#[ExecutionScoped]
final class CallerStateScopedMemo
{
    protected string $tenant = '';

    public function remember(string $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function tenant(): string
    {
        return $this->tenant;
    }
}
