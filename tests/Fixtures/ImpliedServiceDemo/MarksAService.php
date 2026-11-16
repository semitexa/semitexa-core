<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\ImpliedServiceDemo;

use Semitexa\Core\Attribute\ImpliesService;

/** A package's own attribute that says its carriers are services. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MarksAService implements ImpliesService
{
}
