<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: a property named by a literal string is the same property, as a
 * direct target and as a destructuring one. A name computed at run time is
 * not resolved and stays unreported.
 *
 * Lines are asserted by the test: append to this file, do not insert.
 */
#[AsService]
final class CallerStateLiteralPropertyName
{
    protected string $tenant = '';

    protected string $region = '';

    protected string $computed = '';

    public function remember(string $tenant): void
    {
        $this->{'tenant'} = $tenant;
    }

    public function place(string $region): void
    {
        [$this->{'region'}] = [$region];
    }

    public function compute(string $name, string $value): void
    {
        $this->{$name} = $value;
    }

    public function all(): string
    {
        return $this->tenant . $this->region . $this->computed;
    }
}
