<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\ResolverOrder\B;

use Semitexa\Core\Tests\Fixtures\ResolverOrder\Clock;

/** A class whose own name is a generated alias in another case (review, core#245). */
final class FixedClockALIAS1 implements Clock
{
}
