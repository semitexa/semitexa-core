<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: a class declared inside a method is another object. Its
 * constructor's $this and $tenant are its own, so its write is not the
 * service's; the service's own write after it is, at its own line.
 *
 * Lines are asserted by the test: append to this file, do not insert.
 */
#[AsService]
final class CallerStateNestedClass
{
    protected string $tenant = '';

    protected string $label = '';

    protected ?object $holder = null;

    public function make(string $tenant): object
    {
        $made = new class ($tenant) {
            public string $tenant = '';

            public function __construct(string $tenant)
            {
                $this->tenant = $tenant;
            }
        };
        $this->tenant = $tenant;

        return $made;
    }

    public function describe(string $label): object
    {
        return new class {
            public string $label = '';

            public function relabel(string $label): void
            {
                $this->label = $label;
            }
        };
    }

    public function hold(string $tenant): void
    {
        $this->holder = new class ($tenant) {
            public function __construct(public string $tenant)
            {
            }
        };
    }

    public function label(): string
    {
        return $this->tenant . $this->label . ($this->holder === null ? '' : 'held');
    }
}
