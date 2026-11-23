<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

/**
 * Fixture: an attribute whose class does not exist says nothing to the
 * container, so the class is not a service and the rule stays silent (and
 * does not fail while asking what the attribute implements).
 */
#[\NoSuchCallerStateAttribute]
final class CallerStateUnknownAttribute
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
