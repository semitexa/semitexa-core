<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit\Fixture;

use Semitexa\Core\Attribute\RateLimit;

/** Not final: PayloadFactory extends it when a module adds a payload part. */
#[RateLimit(1, 60)]
class LimitedPayloadWithParts
{
}
