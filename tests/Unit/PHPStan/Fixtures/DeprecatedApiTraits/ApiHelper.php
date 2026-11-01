<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApiTraits;

/** @api */
trait ApiHelper
{
    /** @deprecated soon */
    public const OLD_LIMIT = 10;

    /** @deprecated soon */
    public function old(): void
    {
    }

    public function current(): void
    {
    }

    /** @deprecated 2026.10.01 Use current() instead. */
    public function dated(): void
    {
    }
}
