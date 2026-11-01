<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApi;

/** @api */
final class OneLineApi
{
    /** @deprecated soon */
    public function old(): int
    {
        return 0;
    }

    /**
     * @api
     * @deprecated soon
     */
    public int $count = 0;
}
