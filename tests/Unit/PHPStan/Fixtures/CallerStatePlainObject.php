<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

/**
 * Fixture: not container-managed, so whoever creates it owns its lifetime and
 * WorkerServiceCallerStateRule has nothing to say about it.
 */
final class CallerStatePlainObject
{
    private string $tenant = '';

    public function remember(string $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function tenant(): string
    {
        return $this->tenant;
    }
}
