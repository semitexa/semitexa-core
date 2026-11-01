<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApi;

final class Internal
{
    public function __construct(
        /**
         * @api
         * @deprecated soon
         */
        public string $legacy = '',
    ) {
    }

    /** @deprecated whenever */
    public function anything(): int
    {
        return 0;
    }

    /**
     * @api
     * @deprecated soon
     */
    public function markedOnItsOwn(): int
    {
        return 0;
    }
}
