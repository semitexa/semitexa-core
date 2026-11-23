<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: an #[AsService] carrying some other attribute that is merely named
 * ExecutionScoped. The container does not know it and still shares the
 * instance, so WorkerServiceCallerStateRule must report the write.
 *
 * Lines are asserted by the test: append to this file, do not insert.
 */
#[AsService]
#[\ExecutionScoped]
final class CallerStateForeignScoped
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
