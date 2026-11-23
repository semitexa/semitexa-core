<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\ImpliesService;

/** Fixture: a package's own attribute whose carriers the container registers as services. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CallerStateMarksAService implements ImpliesService
{
}
