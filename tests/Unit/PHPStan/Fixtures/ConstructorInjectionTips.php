<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\Tips;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsService;

#[AsService]
final class TipServiceWithConstructorInjection
{
    public function __construct(private readonly \stdClass $dependency)
    {
    }
}

#[AsCommand(name: 'fixture:tip', description: 'fixture')]
final class TipCommandWithConstructorInjection
{
    public function __construct(private readonly \stdClass $dependency)
    {
    }
}
