<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApiTraits;

trait PromotesApi
{
    public function __construct(
        /**
         * @api
         * @deprecated soon
         */
        public int $count = 0,
    ) {
    }
}
