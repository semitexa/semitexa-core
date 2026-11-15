<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsPayloadHandler;

#[AsPayloadHandler(payload: \stdClass::class, resource: \stdClass::class)]
final class ScopedFixtureHandler
{
}
