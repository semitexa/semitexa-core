<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: a method that is merely named initialize. The container's hook is
 * InitializesAfterInjectionInterface::initialize(), without a parameter; this
 * one is a setter any handler may call, so the rule must report it.
 *
 * Lines are asserted by the test: append to this file, do not insert.
 */
#[AsService]
final class CallerStateInitializeSetter
{
    protected string $tenant = '';

    public function initialize(string $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function tenant(): string
    {
        return $this->tenant;
    }
}
