<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

interface FactoryStringKeyed
{
    public function get(string $key): FixtureChannel;
}
