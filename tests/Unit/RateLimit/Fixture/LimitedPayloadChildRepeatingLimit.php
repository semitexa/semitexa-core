<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit\Fixture;

use Semitexa\Core\Attribute\RateLimit;

/** Declares again the very limit of the class it extends: same bucket, limit, window and key. */
#[RateLimit(1, 60, bucket: LimitedPayloadWithParts::class)]
final class LimitedPayloadChildRepeatingLimit extends LimitedPayloadWithParts
{
}
