<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\ExecutionScoped;

/**
 * Fixture: an implied service that is cloned per request. The scoping
 * attribute applies to it as to an #[AsService], so the rule stays silent.
 */
#[CallerStateMarksAService]
#[ExecutionScoped]
final class CallerStateImpliedScoped
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
