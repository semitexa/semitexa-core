<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit\Fixture;

use Semitexa\Core\Attribute\RateLimit;

/** Declares a looser limit than the class it extends. */
#[RateLimit(5, 60)]
final class LimitedPayloadChildWithOwnLimit extends LimitedPayloadWithParts
{
}
