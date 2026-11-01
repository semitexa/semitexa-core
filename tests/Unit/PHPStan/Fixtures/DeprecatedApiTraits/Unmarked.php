<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApiTraits;

trait Unmarked
{
    /** @deprecated whenever */
    public function anything(): void
    {
    }
}
