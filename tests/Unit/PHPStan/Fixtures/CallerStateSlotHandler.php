<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: a slot handler. InjectionAnalyzer puts its class in the execution-scoped
 * set, so the container clones it per request and the rule stays silent. The
 * attribute belongs to another package and is named in full: nothing here
 * needs its class to exist.
 */
#[AsService]
#[\Semitexa\Ssr\Attribute\AsSlotHandler]
final class CallerStateSlotHandler
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
