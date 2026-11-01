<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApi;

final class Spaced
{
    /**
     *  @api
     *  @deprecated  soon
     *  @see Internal
     */
    public function old(): int
    {
        return 0;
    }
}
