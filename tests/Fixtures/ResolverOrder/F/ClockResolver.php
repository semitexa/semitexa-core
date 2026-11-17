<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\ResolverOrder\F;

use Semitexa\Core\Tests\Fixtures\ResolverOrder\Clock;

/** Named like the resolver generated for Clock: imported unaliased, the file would not compile. */
final class ClockResolver implements Clock
{
}
