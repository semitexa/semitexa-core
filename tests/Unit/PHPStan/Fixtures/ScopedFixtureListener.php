<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Event\EventExecution;

#[AsEventListener(event: \stdClass::class, execution: EventExecution::Sync)]
final class ScopedFixtureListener
{
}
