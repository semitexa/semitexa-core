<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

/**
 * Fixture: no #[AsService], only an attribute that implements ImpliesService.
 * ServiceRegistrationPhase registers the class and the container shares it, so
 * WorkerServiceCallerStateRule must report the write.
 *
 * Lines are asserted by the test: append to this file, do not insert.
 */
#[CallerStateMarksAService]
final class CallerStateImpliedService
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
